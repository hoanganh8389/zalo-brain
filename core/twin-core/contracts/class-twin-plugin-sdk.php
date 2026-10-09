<?php
/**
 * Public seven-verb facade for Twin Plugin SDK extensions.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 1.3.8
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Plugin_SDK' ) ) {
	final class BizCity_Twin_Plugin_SDK {

		public static function register_plugin( $module ): bool {
			$registered = self::append_filter( 'bizcity_register_module', $module );
			// [2026-10-09 11:45 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-2b — D96-16 wrapper: also record the module in Zalo Brain (record-only).
			self::zalo_brain_mirror( is_array( $module ) ? $module : array( 'id' => is_string( $module ) ? $module : '' ) );
			return $registered;
		}

		public static function register_tool( $tool ): bool {
			return self::append_filter( 'bizcity_twin_register_tool', $tool );
		}

		public static function register_skill( $skill ): bool {
			return self::append_filter( 'bizcity_twin_register_skill', $skill );
		}

		public static function register_source( $source ): bool {
			return self::append_filter( 'bizcity_twin_register_source', $source );
		}

		public static function register_event( $event_type, array $definition = array() ): bool {
			return class_exists( 'BizCity_Event_Registry' ) && BizCity_Event_Registry::register_event( $event_type, $definition );
		}

		public static function register_diagnostic( $probe ): bool {
			return self::append_filter( 'bizcity_diagnostics_register_probes', $probe );
		}

		public static function register_ui( array $definition ): bool {
			$registered = false;
			if ( isset( $definition['navigation'] ) && is_array( $definition['navigation'] ) && class_exists( 'BizCity_Admin_Navigation_Registry' ) ) {
				$registered = BizCity_Admin_Navigation_Registry::register_item( $definition['navigation'] ) || $registered;
			}
			if ( isset( $definition['setting_panel'] ) && is_array( $definition['setting_panel'] ) && class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
				// [2026-09-13 09:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — route additive UI metadata through the single Setting Panel registry.
				$setting_items = isset( $definition['setting_panel'][0] ) && is_array( $definition['setting_panel'][0] )
					? $definition['setting_panel']
					: array( $definition['setting_panel'] );
				foreach ( $setting_items as $setting_item ) {
					if ( is_array( $setting_item ) ) {
						$registered = BizCity_Setting_Panel_Registry::register_item( $setting_item ) || $registered;
					}
				}
			}
			if ( isset( $definition['output_renderer'] ) ) {
				$registered = self::append_filter( 'bizcity_twin_register_extension_capabilities', $definition['output_renderer'], 'output_renderers' ) || $registered;
			}
			// [2026-10-09 11:45 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-2b — D96-16 wrapper: navigation items are also
			// recorded under the Zalo Brain 'setting' surface (setting_panel items are recorded by BizCity_Setting_Panel_Registry).
			if ( isset( $definition['navigation'] ) && is_array( $definition['navigation'] ) ) {
				self::zalo_brain_mirror( $definition['navigation'], 'setting' );
			}
			return $registered;
		}

		/**
		 * Record-only copy into BizCity_Zalo_Brain (contract zalo-brain-extension@1). Never changes this facade's result.
		 *
		 * @param array<string,mixed> $def
		 * @param string              $surface '' = extension/module, else a Zalo Brain surface key.
		 */
		private static function zalo_brain_mirror( array $def, $surface = '' ): void {
			if ( class_exists( 'BizCity_Zalo_Brain', false ) && method_exists( 'BizCity_Zalo_Brain', 'mirror' ) ) {
				BizCity_Zalo_Brain::mirror( 'twin-plugin-sdk', $def, (string) $surface );
			}
		}

		/**
		 * @param string $filter
		 * @param mixed  $value
		 * @param string $group
		 * @return bool
		 */
		private static function append_filter( $filter, $value, $group = '' ): bool {
			if ( ! function_exists( 'add_filter' ) ) {
				return false;
			}
			add_filter( $filter, function ( $items ) use ( $value, $group ) {
				if ( 'output_renderers' === $group ) {
					if ( ! is_array( $items ) ) {
						$items = array();
					}
					if ( ! isset( $items[ $group ] ) || ! is_array( $items[ $group ] ) ) {
						$items[ $group ] = array();
					}
					$items[ $group ][] = $value;
					return $items;
				}
				if ( ! is_array( $items ) ) {
					$items = array();
				}
				$items[] = $value;
				return $items;
			}, 20, 1 );
			return true;
		}
	}
}