<?php
/**
 * BizCity_PiAPI_Client - Gateway-only client for PiAPI image tasks.
 *
 * Provider credentials never live on the client. All calls use the canonical
 * BizCity_LLM_Client gateway URL and opaque API key boundary.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since 1.25.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_PiAPI_Client' ) ) {
    return;
}

class BizCity_PiAPI_Client {

    const NAMESPACE    = 'piapi/router/v1';
    const TIMEOUT_SEC  = 30;

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function get_gateway_url(): string {
        if ( class_exists( 'BizCity_LLM_Client' ) ) {
            return rtrim( BizCity_LLM_Client::instance()->get_gateway_url(), '/' );
        }
        return rtrim( (string) get_option( 'bizcity_llm_gateway_url', '' ), '/' );
    }

    public function get_api_key(): string {
        if ( class_exists( 'BizCity_LLM_Client' ) ) {
            return BizCity_LLM_Client::instance()->get_api_key();
        }
        return '';
    }

    public function is_ready(): bool {
        return $this->get_gateway_url() !== '' && $this->get_api_key() !== '';
    }

    /**
     * Submit an allowlisted PiAPI image operation through the Hub.
     *
     * @return array
     */
    public function submit_image_task( string $operation, array $input, array $options = array() ): array {
        // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-CL6 — group C: hosted ⇒ Hub (below, unchanged); key ⇒ BYOK
        // (extension provider via bizcity_byok_provider, else 'byok_unsupported' — no documented provider API); else byok_missing.
        $route = $this->group_c_route();
        if ( 'hub' !== $route ) {
            return $this->byok_task( $route, array(
                'action'    => 'submit',
                'operation' => sanitize_key( $operation ),
                'input'     => $input,
                'options'   => $options,
            ) );
        }
        if ( ! $this->is_ready() ) {
            return $this->degraded( 'gateway_degraded' );
        }

        $idempotency_key = sanitize_text_field( (string) ( $options['idempotency_key'] ?? '' ) );
        if ( $idempotency_key === '' ) {
            $idempotency_key = 'imgtask_' . wp_generate_uuid4();
        }
        $trace_id = sanitize_text_field( (string) ( $options['trace_id'] ?? '' ) );
        if ( $trace_id === '' ) {
            $trace_id = 'tr_' . wp_generate_uuid4();
        }

        $request = array(
            'operation'       => sanitize_key( $operation ),
            'input'           => $input,
            'options'         => $options,
            'idempotency_key' => $idempotency_key,
            'trace_id'        => $trace_id,
            'site_url'        => home_url(),
            'plugin_name'     => 'bizcity-tool-image',
        );
        unset( $request['options']['idempotency_key'], $request['options']['trace_id'] );

        $url  = $this->get_gateway_url() . '/wp-json/' . self::NAMESPACE . '/image-task';
        $args = array(
            'method'  => 'POST',
            'timeout' => self::TIMEOUT_SEC,
            // [2026-09-01 Johnny Chu] B2C-G7.1 — send the canonical site signal on PiAPI task submission.
            'headers' => array_merge( array(
                'Authorization' => 'Bearer ' . $this->get_api_key(),
                'Content-Type'  => 'application/json',
                'X-Site-URL'    => home_url(),
                'X-Trace-Id'    => $trace_id,
                'X-Idempotency-Key' => $idempotency_key,
            ), class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->get_client_domain_headers() : array() ),
            'body' => wp_json_encode( $request ),
        );
        $response = $this->request( 'gateway.piapi.image_task.submit', $url, $args, array( 'trace_id' => $trace_id, 'idempotency_key' => $idempotency_key ) );

        return $this->decode_response( $response, $trace_id );
    }

    /**
     * Poll a Hub-owned PiAPI task.
     *
     * @return array
     */
    public function get_task( string $task_id ): array {
        // [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-CL6 — same route as submit_image_task().
        $route = $this->group_c_route();
        if ( 'hub' !== $route ) {
            return $this->byok_task( $route, array(
                'action'  => 'poll',
                'task_id' => sanitize_text_field( $task_id ),
            ) );
        }
        if ( ! $this->is_ready() ) {
            return $this->degraded( 'gateway_degraded' );
        }
        $task_id = sanitize_text_field( $task_id );
        if ( $task_id === '' || ! preg_match( '/^[A-Za-z0-9_-]+$/', $task_id ) ) {
            return array(
                'success'   => false,
                'code'      => 'invalid_param',
                'message'   => 'Mã tác vụ ảnh không hợp lệ.',
                'hint'      => 'Kiểm tra mã tác vụ rồi thử lại.',
                'help_code' => 'image_task_not_found',
            );
        }

        $trace_id = 'tr_' . wp_generate_uuid4();
        $url = $this->get_gateway_url() . '/wp-json/' . self::NAMESPACE . '/task/' . rawurlencode( $task_id );
        $response = $this->request(
            'gateway.piapi.image_task.poll',
            $url,
            array(
                'method'  => 'GET',
                'timeout' => self::TIMEOUT_SEC,
                // [2026-09-01 Johnny Chu] B2C-G7.1 — send the canonical site signal on PiAPI task polling.
                'headers' => array_merge( array(
                    'Authorization' => 'Bearer ' . $this->get_api_key(),
                    'X-Site-URL'    => home_url(),
                    'X-Trace-Id'    => $trace_id,
                ), class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->get_client_domain_headers() : array() ),
            ),
            array( 'user_id' => get_current_user_id(), 'trace_id' => $trace_id )
        );

        return $this->decode_response( $response, $trace_id );
    }

    /**
     * Public gateway health check.
     *
     * @return array
     */
    public function health(): array {
        if ( $this->get_gateway_url() === '' ) {
            return $this->degraded( 'gateway_degraded' );
        }
        $response = $this->request(
            'gateway.piapi.health',
            $this->get_gateway_url() . '/wp-json/' . self::NAMESPACE . '/health',
            array( 'method' => 'GET', 'timeout' => 10 ),
            array()
        );
        return $this->decode_response( $response, '' );
    }

    /** [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.138 S138-CL6 — the one group C decision lives in BizCity_LLM_Client. */
    private function group_c_route(): string {
        return class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'group_c_route' )
            ? BizCity_LLM_Client::group_c_route( 'image_task' )
            : 'hub';
    }

    /**
     * Non-Hub route → this client's array shape ({ success, code, message, hint, help_code } on failure).
     *
     * @param string $route   'byok' | 'none'
     * @param array  $payload { action: submit|poll, … }
     */
    private function byok_task( string $route, array $payload ): array {
        if ( 'byok' === $route && class_exists( 'BizCity_BYOK', false ) ) {
            $r = BizCity_BYOK::image_task( $payload );
            if ( is_array( $r ) ) {
                return $r;
            }
        } else {
            $r = BizCity_LLM_Client::group_c_missing_error();
        }
        $data = $r->get_error_data();
        return array(
            'success'   => false,
            'code'      => (string) $r->get_error_code(),
            'message'   => $r->get_error_message(),
            'hint'      => is_array( $data ) ? (string) ( $data['hint'] ?? '' ) : '',
            'help_code' => (string) $r->get_error_code(),
            'status'    => is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 502,
        );
    }

    private function request( string $name, string $url, array $args, array $context = array() ) {
        // [2026-08-10 Johnny Chu] PHASE-1.25-PIAPI — use shared retry/deadline/circuit policy when loaded.
        if ( class_exists( 'BizCity_Twin_Reliable_HTTP' ) ) {
            return BizCity_Twin_Reliable_HTTP::request( $name, $url, $args, $context );
        }
        return wp_remote_request( $url, $args );
    }

    private function decode_response( $response, string $trace_id ): array {
        if ( is_wp_error( $response ) ) {
            return $this->degraded( 'gateway_degraded', $trace_id );
        }
        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return $this->degraded( 'gateway_degraded', $trace_id );
        }
        if ( empty( $body['success'] ) && empty( $body['code'] ) ) {
            $code = $http_status === 401 ? 'auth_required'
                : ( $http_status === 403 ? 'permission_denied'
                : ( $http_status === 429 ? 'rate_limited'
                : ( $http_status >= 500 ? 'provider_error' : 'gateway_degraded' ) ) );
            return array(
                'success'   => false,
                '_degraded' => $http_status >= 500,
                'code'      => $code,
                'message'   => $code === 'auth_required' ? 'BizCity API key không hợp lệ.' : 'Dịch vụ PiAPI tạm thời không khả dụng.',
                'hint'      => $code === 'auth_required' ? 'Kiểm tra API key rồi thử lại.' : 'Thử lại sau vài phút.',
                'help_code' => $code === 'auth_required' ? 'gateway_auth' : 'gateway_degraded',
                'trace_id'  => $trace_id,
            );
        }
        if ( $trace_id !== '' && empty( $body['trace_id'] ) ) {
            $body['trace_id'] = $trace_id;
        }
        return $body;
    }

    private function degraded( string $code, string $trace_id = '' ): array {
        return array(
            'success'   => false,
            '_degraded' => true,
            'code'      => $code,
            'message'   => 'Dịch vụ PiAPI tạm thời không khả dụng.',
            'hint'      => 'Thử lại sau vài phút.',
            'help_code' => 'gateway_degraded',
            'trace_id'  => $trace_id,
        );
    }
}
