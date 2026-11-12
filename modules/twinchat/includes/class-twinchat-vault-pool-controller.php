<?php
/**
 * Bizcity Twin AI — TwinChat · "Lưu vào kho tri thức": the notebook's pool file (vault-append@1).
 *
 * [2026-10-27 07:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.143 S143-L6 (D143-8…12) —
 *   GET  bizcity-twinchat/v1/notebooks/{id}/vault-pool               ⇒ {path, folder, entries_count, folders[], suggest[]} (what the folder sheet and the Vault tab read)
 *   POST bizcity-twinchat/v1/notebooks/{id}/vault-pool/migrate-pins  ⇒ the old 📌 notes of the notebook become entries of the pool (once; idempotent per note)
 * The write itself is BizCity_Twin_Vault::append (REST bizcity-vault/v1/note/append); nothing here writes a file.
 *
 * @package Bizcity_Twin_AI
 * @subpackage TwinChat
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinChat_Vault_Pool_Controller {

	private static $instance = null;

	public static function instance(): self {
		return self::$instance ?? ( self::$instance = new self() );
	}

	public function register_routes(): void {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';
		$ok = static function () { return current_user_can( 'edit_posts' ); };
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/vault-pool', array( 'methods' => 'GET', 'callback' => array( $this, 'get_pool' ), 'permission_callback' => $ok ) );
		register_rest_route( $ns, '/notebooks/(?P<id>\d+)/vault-pool/migrate-pins', array( 'methods' => 'POST', 'callback' => array( $this, 'migrate_pins' ), 'permission_callback' => $ok ) );
	}

	/** The readable notebook of the caller, or a WP_Error. */
	private function notebook( int $id ) {
		$uid = (int) get_current_user_id();
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) || ! class_exists( 'BizCity_Twin_Vault' ) ) {
			return new WP_Error( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', array( 'status' => 503 ) );
		}
		$nb = BizCity_KG_Notebook_Service::instance()->get( $id );
		if ( ! is_array( $nb ) || ( class_exists( 'BizCity_KG_Access' ) && ! BizCity_KG_Access::can_read_notebook( $id, $uid ) ) ) {
			return new WP_Error( 'vault_not_found', 'Không có notebook này.', array( 'status' => 404 ) );
		}
		return $nb;
	}

	public function get_pool( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$nb = $this->notebook( $id );
		if ( is_wp_error( $nb ) ) {
			return $nb;
		}
		$pool    = (array) ( ( (array) ( $nb['settings'] ?? array() ) )['vault_pool'] ?? array() );
		$ctx     = array( 'owner_user_id' => get_current_user_id(), 'vrole' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
		$folders = array_values( array_filter( BizCity_Twin_Vault::folders(), static function ( $f ) use ( $ctx ) {
			return BizCity_Twin_Vault::folder_visible( (string) $f['n'], BizCity_Twin_Vault::role_of( $ctx ), BizCity_Twin_Vault::access_map() );
		} ) );
		// the folders of the Guru attached to this notebook come first (that Guru reads them: R-SCOPE-FOLDER), then the keyword guess
		$suggest = (int) ( $nb['character_id'] ?? 0 ) > 0 ? BizCity_Twin_Vault::folders_for_guru( (int) $nb['character_id'] ) : array();
		$guess   = BizCity_Twin_Vault::suggest_for_row( array( 'title' => (string) $nb['name'], 'text' => (string) ( $nb['description'] ?? '' ), 'meta' => array( 'keywords' => array() ), 'vault_folder' => '' ), $ctx );
		if ( is_array( $guess ) && '' !== (string) ( $guess['n'] ?? $guess['folder'] ?? '' ) ) {
			$suggest[] = (string) ( $guess['n'] ?? $guess['folder'] );
			$suggest   = array_values( array_unique( $suggest ) );
		}
		return rest_ensure_response( array(
			'contract'      => 'vault-append@1',
			'notebook_id'   => $id,
			'notebook_name' => (string) $nb['name'],
			'path'          => '' !== (string) ( $pool['path'] ?? '' ) ? 'lib:' . (string) $pool['path'] : '',
			'folder'        => (string) ( $pool['folder'] ?? '' ),
			'entries_count' => (int) ( $pool['entries_count'] ?? 0 ),
			'chosen_at'     => (string) ( $pool['chosen_at'] ?? '' ),
			'folders'       => array_map( static function ( $f ) { return array( 'n' => $f['n'], 'label' => $f['label'], 'icon' => $f['icon'], 'access' => $f['access'] ); }, $folders ),
			'suggest'       => $suggest,
			'old_pins'      => $this->old_pins( $id ),
		) );
	}

	/** Old 📌 notes ({id,title,content,message_id}) of the notebook; [] when the notes service is absent. */
	private function old_pins( int $id ): array {
		if ( ! class_exists( 'BizCity_TwinChat_Notes_Service' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( new BizCity_TwinChat_Notes_Service() )->get_by_project( 'tc_' . $id ) as $n ) {
			if ( 'chat_pinned' === (string) ( $n->note_type ?? '' ) && '' !== trim( (string) ( $n->content ?? '' ) ) ) {
				$out[] = array( 'id' => (int) ( $n->id ?? 0 ), 'title' => (string) ( $n->title ?? '' ), 'content' => (string) $n->content );
			}
		}
		return $out;
	}

	public function migrate_pins( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$nb = $this->notebook( $id );
		if ( is_wp_error( $nb ) ) {
			return $nb;
		}
		$body = $r->get_json_params();
		$ctx  = array( 'role' => 'person', 'owner_user_id' => get_current_user_id(), 'vrole' => current_user_can( 'manage_options' ) ? 'owner' : 'staff' );
		$done = 0;
		$dup  = 0;
		foreach ( $this->old_pins( $id ) as $p ) {
			$res = BizCity_Twin_Vault::append( array(
				'notebook_id' => $id,
				'message_id'  => 'note_' . (int) $p['id'],
				'q'           => '' !== trim( $p['title'] ) ? $p['title'] : mb_substr( wp_strip_all_tags( $p['content'] ), 0, 80 ),
				'answer_md'   => $p['content'],
				'cites'       => array(),
				'folder'      => is_array( $body ) ? (string) ( $body['folder'] ?? '' ) : '',
			), $ctx );
			if ( is_wp_error( $res ) ) {
				return $res; // e.g. vault_pool_folder_needed: the FE opens the folder sheet and calls again
			}
			empty( $res['duplicated'] ) ? $done++ : $dup++;
		}
		return rest_ensure_response( array( 'ok' => true, 'moved' => $done, 'already' => $dup ) );
	}
}
