<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Tool Registry
 *
 * Central registry for all executable tools.
 * Other plugins register their tools via:
 *   add_action('bizcity_intent_register_tools', function($registry) {
 *       $registry->register('create_product', [
 *           'description' => '...',
 *           'input_fields' => [ 'title' => 'required', 'price' => 'required', ... ],
 *           'hil_questions' => [ 'title' => 'Tên sản phẩm?', ... ],
 *       ], 'ClassName::method');
 *   });
 *
 * Tool callbacks receive (array $slots) and return:
 *   [ 'success' => bool, 'message' => '...', 'data' => [...], 'missing_fields' => [...] ]
 *
 * If a tool returns 'missing_fields', the engine transitions the conversation
 * to WAITING_USER for those fields.
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Tools {

    /** @var self|null */
    private static $instance = null;

    /**
     * Registered tools.
     * Structure: [ 'tool_name' => [ 'schema' => [...], 'callback' => callable ] ]
     *
     * @var array
     */
    private $tools = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Built-in tools will be registered in init_builtin()
        add_action( 'init', [ $this, 'init_builtin' ], 20 );
    }

    /**
     * Register built-in tools that bridge to existing BizCity functions.
     *
     * IMPORTANT: Provider plugins register their own tools during
     * `plugins_loaded` (via bizcity_intent_register_providers → boot()).
     * This runs later on `init` priority 20. Built-in tools are fallback
     * bridges only — if a provider already registered a more specialized
     * version, we SKIP the built-in.
     */
    public function init_builtin() {
        // [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R5 — 15 built-ins retired: create_product, generate_report,
        // inventory_report, post_facebook, write_article, set_reminder, edit_product, create_order, list_orders,
        // find_customer, customer_stats, product_stats, inventory_journal, warehouse_receipt, help_guide. Each one only
        // bridged to a legacy twf_* / ai_generate_content flow archived with core/helper-legacy (R-ONE-AXIS R-AX-3),
        // so it could only answer "not available". Their stale bizcity_tool_registry rows are deactivated by
        // BizCity_Intent_Tool_Index::sync_builtin_tools(). Admin work goes through CRM / Automation instead.

        // ── Tool: create_video → bridges to bizcity-video-kling ──
        if ( ! $this->has( 'create_video' ) ) {
        $this->register( 'create_video', [
            'description'  => 'Tạo video bằng AI (Kling)',
            'input_fields' => [
                'content'      => [ 'required' => true,  'type' => 'text' ],
                'title'        => [ 'required' => false, 'type' => 'text' ],
                'duration'     => [ 'required' => false, 'type' => 'number', 'default' => 5 ],
                'aspect_ratio' => [ 'required' => false, 'type' => 'choice', 'default' => '9:16' ],
                'image_url'    => [ 'required' => false, 'type' => 'image' ],
            ],
        ], [ $this, 'builtin_create_video' ] );
        }

        // Note: bizcity_intent_tools_ready hook is now fired from
        // Provider Registry boot() at init:25 (after this method + DB sync).
        // This ensures all late-registered tools are synced to DB.
    }

    /* ================================================================
     *  Registration
     * ================================================================ */

    /**
     * Register a tool.
     *
     * @param string   $name     Unique tool name.
     * @param array    $schema   {
     *   @type string $description   What the tool does.
     *   @type array  $input_fields  [ field_name => [ 'required'=>bool, 'type'=>'...' ] ]
     *   @type array  $output_fields Optional output field descriptions.
     * }
     * @param callable $callback function(array $slots): array
     */
    public function register( $name, array $schema, $callback ) {
        $this->tools[ $name ] = [
            'schema'   => $schema,
            'callback' => $callback,
        ];
    }

    /**
     * Unregister a tool.
     *
     * @param string $name
     */
    public function unregister( $name ) {
        unset( $this->tools[ $name ] );
    }

    /**
     * Check if a tool is registered.
     *
     * @param string $name
     * @return bool
     */
    public function has( $name ) {
        return isset( $this->tools[ $name ] );
    }

    /**
     * Get tool schema.
     *
     * @param string $name
     * @return array|null
     */
    public function get_schema( $name ) {
        return isset( $this->tools[ $name ] ) ? $this->tools[ $name ]['schema'] : null;
    }

    /**
     * Get tool callback.
     *
     * @param string $name Tool name.
     * @return callable|null
     */
    public function get_callback( $name ) {
        return $this->tools[ $name ]['callback'] ?? null;
    }

    /**
     * Check if a tool declares auto_execute (skip confirm for read-only tools).
     *
     * @param string $name Tool name.
     * @return bool
     */
    public function is_auto_execute( $name ) {
        $schema = $this->get_schema( $name );
        return ! empty( $schema['auto_execute'] );
    }

    /**
     * Get tool source (for execution logging + provider classification).
     *
     * S8 fix: distinguish core atomic tools (bizcity_atomic_* callbacks)
     * from plugin tools for SmartClassifier tool filtering.
     *
     * @param string $name Tool name.
     * @return string 'built_in' | 'plugin' | 'provider' | 'unknown'
     */
    public function get_tool_source( $name ) {
        if ( ! isset( $this->tools[ $name ] ) ) {
            return 'unknown';
        }

        $callback = $this->tools[ $name ]['callback'];

        // Check if it's a built-in (method on this class)
        if ( is_array( $callback ) && $callback[0] === $this ) {
            return 'built_in';
        }

        // Check if it's from a provider (class name contains 'Provider')
        if ( is_array( $callback ) && is_object( $callback[0] ) ) {
            $class_name = get_class( $callback[0] );
            if ( strpos( $class_name, 'Provider' ) !== false ) {
                return 'provider';
            }
        }

        // S8: Core atomic tools use bizcity_atomic_* callback naming convention
        // (registered in core/tools/*/bootstrap.php). Treat as built-in.
        if ( is_string( $callback ) && strpos( $callback, 'bizcity_atomic_' ) === 0 ) {
            return 'built_in';
        }

        // Domain atomic tools: scheduler_* prefix — registered via BizCity_Intent_Simple_Provider
        // but are first-class domain tools that should rank above content tools in tool registry.
        if ( is_array( $name ) ) {
            $name = '';
        }
        if ( is_string( $name ) && strpos( $name, 'scheduler_' ) === 0 ) {
            return 'built_in';
        }

        return 'plugin';
    }

    /**
     * List all registered tools.
     *
     * @return array [ name => schema ]
     */
    public function list_all() {
        $result = [];
        foreach ( $this->tools as $name => $tool ) {
            $result[ $name ] = $tool['schema'];
        }
        return $result;
    }

    /* ================================================================
     *  Execution
     * ================================================================ */

    /**
     * Execute a tool with given slots.
     *
     * @param string $name  Tool name.
     * @param array  $slots Input parameters.
     * @return array {
     *   @type bool   $success
     *   @type string $message       Human-readable result.
     *   @type array  $data          Structured output data.
     *   @type array  $missing_fields  Fields still needed (tool can request more info).
     * }
     */
    public function execute( $name, array $slots ) {
        if ( ! $this->has( $name ) ) {
            return [
                'success'        => false,
                'message'        => "Tool '{$name}' không được tìm thấy.",
                'data'           => [],
                'missing_fields' => [],
            ];
        }

        $tool = $this->tools[ $name ];

        // Validate required fields
        $missing = $this->validate_inputs( $name, $slots );
        if ( ! empty( $missing ) ) {
            return [
                'success'        => false,
                'message'        => 'Thiếu thông tin: ' . implode( ', ', $missing ),
                'data'           => [],
                'missing_fields' => $missing,
            ];
        }

        // ── Inject _trace context so tool callbacks can report progress ──
        // Callbacks access: $slots['_trace'] (BizCity_Job_Trace instance or null)
        // If the tool callback creates its own trace via BizCity_Job_Trace::start(),
        // the _trace slot provides session_id for convenience.
        $session_id_for_trace = $slots['session_id'] ?? ( $slots['_meta']['session_id'] ?? '' );
        $slots['_trace_session_id'] = $session_id_for_trace;

        // Execute callback
        try {
            $callback = $tool['callback'];
            if ( is_callable( $callback ) ) {
                $result = call_user_func( $callback, $slots );
            } else {
                return [
                    'success'        => false,
                    'message'        => "Tool '{$name}' callback không hợp lệ.",
                    'data'           => [],
                    'missing_fields' => [],
                ];
            }

            // Normalize result
            if ( ! is_array( $result ) ) {
                $result = [
                    'success' => true,
                    'message' => (string) $result,
                    'data'    => [],
                ];
            }
            if ( ! isset( $result['missing_fields'] ) ) {
                $result['missing_fields'] = [];
            }

            // Sprint 1E: Warn-only output convention check for pipeline chaining readiness.
            // Tools SHOULD include data.type (+ data.id for write ops) so multi-step
            // pipelines can reference outputs. Warn now, enforce later.
            if ( ! empty( $result['success'] ) && ! empty( $result['data'] ) && is_array( $result['data'] ) ) {
                if ( empty( $result['data']['type'] ) ) {
                    // Auto-fill data.type from tool name as bootstrap convention
                    $result['data']['type'] = $name;
                    error_log( "[tool-output-convention] Tool '{$name}' missing data.type — auto-filled from tool name" );
                }
            }

            // ── Auto-complete any active trace that the callback forgot to close ──
            // [2026-09-25 Claude Opus 5.5] WP-11 C4b — Job_Trace is owned by core/runtime now; degrade if it is not loaded.
            $active_trace = class_exists( 'BizCity_Job_Trace' ) ? BizCity_Job_Trace::current() : null;
            if ( $active_trace && $active_trace->get_status() === 'running' ) {
                if ( ! empty( $result['success'] ) ) {
                    $active_trace->complete( $result['data'] ?? [] );
                } else {
                    $active_trace->fail( $result['message'] ?? 'Unknown error' );
                }
            }

            // ── Phase 1: Auto-save evidence CPT after successful execution ──
            if ( ! empty( $result['success'] ) && class_exists( 'BizCity_Tool_Evidence' ) ) {
                $evidence_context = [
                    'session_id'  => $slots['session_id'] ?? ( $slots['_meta']['session_id'] ?? '' ),
                    'pipeline_id' => $slots['_pipeline_id'] ?? '',
                    'step_index'  => $slots['_step_index'] ?? null,
                    'user_id'     => $slots['user_id'] ?? get_current_user_id(),
                ];
                $evidence_id = BizCity_Tool_Evidence::save( $name, $result, $evidence_context );
                if ( $evidence_id && is_array( $result['data'] ?? null ) ) {
                    $result['data']['evidence_id'] = $evidence_id;
                }
            }

            return $result;

        } catch ( \Exception $e ) {
            error_log( "[BizCity_Intent_Tools] Error executing '{$name}': " . $e->getMessage() );

            // ── Auto-fail any active trace on exception ──
            $active_trace = class_exists( 'BizCity_Job_Trace' ) ? BizCity_Job_Trace::current() : null;
            if ( $active_trace && $active_trace->get_status() === 'running' ) {
                $active_trace->fail( $e->getMessage() );
            }

            return [
                'success'        => false,
                'message'        => 'Lỗi khi thực hiện: ' . $e->getMessage(),
                'data'           => [],
                'missing_fields' => [],
            ];
        }
    }

    /* ================================================================
     *  Execute with Preconfirm (Phase 1)
     * ================================================================ */

    /**
     * Execute a tool with preconfirm flow.
     *
     * Returns a preconfirm request if the tool requires user confirmation,
     * or executes directly if auto_execute is set or _confirmed is present.
     *
     * @param string $name           Tool name.
     * @param array  $slots          Input parameters.
     * @param array  $session_context Pipeline context.
     * @return array
     */
    public function execute_with_preconfirm( $name, array $slots, $session_context = [] ) {
        if ( ! $this->has( $name ) ) {
            return [
                'success' => false,
                'message' => "Tool '{$name}' không được tìm thấy.",
                'data'    => [],
            ];
        }

        // 1. Validate required fields
        $missing = $this->validate_inputs( $name, $slots );
        if ( ! empty( $missing ) ) {
            return [
                'success'        => false,
                'action'         => 'ask_user',
                'message'        => 'Cần bổ sung: ' . implode( ', ', $missing ),
                'missing_fields' => $missing,
                'current_slots'  => $slots,
            ];
        }

        // 2. Check auto_execute or already confirmed
        $auto = $this->is_auto_execute( $name );
        if ( ! $auto && empty( $slots['_confirmed'] ) ) {
            // 3. Return preconfirm request
            return [
                'success' => false,
                'action'  => 'preconfirm',
                'message' => $this->build_preconfirm_message( $name, $slots ),
                'tool'    => $name,
                'slots'   => $slots,
            ];
        }

        // 4. Execute (remove internal flag before passing to callback)
        unset( $slots['_confirmed'] );

        // Inject pipeline context
        if ( ! empty( $session_context['pipeline_id'] ) ) {
            $slots['_pipeline_id'] = $session_context['pipeline_id'];
        }
        if ( isset( $session_context['step_index'] ) ) {
            $slots['_step_index'] = $session_context['step_index'];
        }

        return $this->execute( $name, $slots );
    }

    /**
     * Build a human-readable preconfirm message showing planned input.
     *
     * @param string $name  Tool name.
     * @param array  $slots Input slots.
     * @return string
     */
    private function build_preconfirm_message( $name, array $slots ) {
        $schema  = $this->get_schema( $name );
        $desc    = $schema['description'] ?? $name;
        $lines   = [ "✅ Sắp thực hiện: **{$desc}**", '' ];

        foreach ( $slots as $field => $value ) {
            if ( str_starts_with( $field, '_' ) ) continue;
            if ( in_array( $field, [ 'session_id', 'user_id', 'platform' ], true ) ) continue;
            if ( $value === '' || $value === null ) continue;

            $display = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value;
            if ( mb_strlen( $display, 'UTF-8' ) > 80 ) {
                $display = mb_substr( $display, 0, 77, 'UTF-8' ) . '...';
            }
            $lines[] = "• **{$field}**: {$display}";
        }

        $lines[] = '';
        $lines[] = 'Xác nhận? ✅ OK | ❌ Hủy | ✏️ Sửa';

        return implode( "\n", $lines );
    }

    /**
     * Validate tool inputs against schema.
     *
     * @param string $name
     * @param array  $slots
     * @return array Missing required field names.
     */
    public function validate_inputs( $name, array $slots ) {
        $schema = $this->get_schema( $name );
        if ( ! $schema || empty( $schema['input_fields'] ) ) {
            return [];
        }

        $missing = [];
        foreach ( $schema['input_fields'] as $field => $config ) {
            if ( ! empty( $config['required'] ) ) {
                $value = $slots[ $field ] ?? null;
                if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
                    $missing[] = $field;
                }
            }
        }

        return $missing;
    }

    /* ================================================================
     *  Built-in tool implementations
     * ================================================================ */

    /**
     * Built-in: Create video via Kling — saves script, returns link for generation.
     * This is async: script saved immediately, video generation queued separately.
     */
    public function builtin_create_video( array $slots ) {
        if ( class_exists( 'BizCity_Video_Kling_Database' ) ) {
            // Guard: verify save_script() method exists — prevents fatal if Kling plugin
            // renamed or restructured the Database class.
            if ( ! method_exists( 'BizCity_Video_Kling_Database', 'save_script' ) ) {
                error_log( '[INTENT-TOOLS] create_video: BizCity_Video_Kling_Database exists but save_script() method not found' );
                return [
                    'success' => false,
                    'message' => 'Plugin Kling đã cài nhưng thiếu method save_script(). Vui lòng cập nhật plugin.',
                    'data'    => [],
                ];
            }

            $title        = $slots['title'] ?? ( 'Video: ' . mb_substr( $slots['content'] ?? '', 0, 40 ) );
            $content      = $slots['content'] ?? '';
            $duration     = intval( $slots['duration'] ?? 5 );
            $aspect_ratio = $slots['aspect_ratio'] ?? '9:16';
            // Sanitize image_url: must be a valid URL, not user text leaking from slot filling
            $image_url    = $slots['image_url'] ?? '';
            if ( $image_url && ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $image_url = '';
            }

            $script_data = [
                'title'        => $title,
                'content'      => $content,
                'duration'     => $duration,
                'aspect_ratio' => $aspect_ratio,
                'model'        => 'kling-v2',
                'status'       => 'active',
                'metadata'     => wp_json_encode( [
                    'image_url'  => $image_url,
                    'source'     => 'intent_engine',
                    'created_by' => get_current_user_id(),
                ] ),
            ];

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::log( 'tool_step', [
                    'tool_name' => 'create_video', 'sub_step' => '1/2 save_script',
                    'status' => 'running', 'title' => $title,
                ] );
            }

            // [2026-09-25 Claude Opus 5.5] FATAL-SWEEP — the Kling repo exposes create_script(), not save_script().
            $script_id = method_exists( 'BizCity_Video_Kling_Database', 'save_script' )
                ? BizCity_Video_Kling_Database::save_script( $script_data )
                : BizCity_Video_Kling_Database::create_script( $script_data );

            if ( $script_id ) {
                do_action( 'bizcity_intent_tool_create_video', $slots, $script_id );

                $edit_url = admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . $script_id );

                if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                    BizCity_Execution_Logger::log( 'tool_step', [
                        'tool_name' => 'create_video', 'sub_step' => '1/2 save_script',
                        'status' => 'success', 'post_id' => $script_id, 'title' => $title, 'url' => $edit_url,
                    ] );
                    BizCity_Execution_Logger::log( 'tool_step', [
                        'tool_name' => 'create_video', 'sub_step' => '2/2 queue_generation',
                        'status' => 'skipped', 'message' => 'Async — user must click Generate link',
                    ] );
                }

                return [
                    'success'  => true,
                    'complete' => true,  // Script saved = goal achieved
                    'message'  => sprintf(
                        "🎬 Đã tạo script video \"%s\" (%d giây, %s).\n👉 Bấm vào đây để tạo video: %s",
                        $title, $duration, $aspect_ratio, $edit_url
                    ),
                    'data' => [ 'script_id' => $script_id, 'url' => $edit_url ],
                ];
            }

            if ( class_exists( 'BizCity_Execution_Logger' ) ) {
                BizCity_Execution_Logger::error( 'tool_error', 'create_video: save_script thất bại', [
                    'tool' => 'create_video', 'title' => $title,
                ] );
            }

            return [
                'success' => false,
                'message' => 'Không thể tạo script video. Vui lòng thử lại.',
                'data'    => [],
            ];
        }

        return [
            'success' => false,
            'message' => 'Plugin tạo video (Kling) chưa được cài đặt.',
            'data'    => [],
        ];
    }

}
