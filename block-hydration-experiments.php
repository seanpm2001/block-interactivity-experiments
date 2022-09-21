<?php
/**
 * Plugin Name:       block-hydration-experiments
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      5.6
 * Author:            Gutenberg Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       block-hydration-experiments
 */
function block_hydration_experiments_init()
{
	wp_enqueue_script('vendors', plugin_dir_url(__FILE__) . 'build/vendors.js');

	wp_register_script(
		'hydration',
		plugin_dir_url(__FILE__) . 'build/gutenberg-packages/hydration.js',
		[],
		'1.0.0',
		true
	);

	register_block_type(
		plugin_dir_path(__FILE__) . 'build/blocks/interactive-child/'
	);
	register_block_type(
		plugin_dir_path(__FILE__) . 'build/blocks/interactive-parent/'
	);
	register_block_type(
		plugin_dir_path(__FILE__) . 'build/blocks/non-interactive-parent/'
	);
}
add_action('init', 'block_hydration_experiments_init');

add_filter('render_block_bhe/interactive-child', function ($content) {
	wp_enqueue_script(
		'bhe/interactive-child/view',
		plugin_dir_url(__FILE__) .
			'build/blocks/interactive-child/register-view.js'
	);
	return $content;
});

add_filter('render_block_bhe/interactive-parent', function ($content) {
	wp_enqueue_script(
		'bhe/interactive-parent/view',
		plugin_dir_url(__FILE__) .
			'build/blocks/interactive-parent/register-view.js'
	);
	return $content;
});

function bhe_block_wrapper($block_content, $block, $instance)
{
	$block_type = $instance->block_type;

	if (!block_has_support($block_type, ['view'])) {
		return $block_content;
	}

	wp_enqueue_script('hydration');

	$attr_definitions = $block_type->attributes;

	$attributes = [];
	$sourced_attributes = [];
	foreach ($attr_definitions as $attr => $definition) {
		if (!empty($definition['public'])) {
			if (!empty($definition['source'])) {
				$sourced_attributes[$attr] = [
					'selector' => $definition['selector'], // TODO: Guard against unset.
					'source' => $definition['source'],
				];
			} else {
				if (array_key_exists($attr, $block['attrs'])) {
					$attributes[$attr] = $block['attrs'][$attr];
				} elseif (isset($definition['default'])) {
					$attributes[$attr] = $definition['default'];
				}
			}
		}
	}

	// We might want to use a flag from block.json as the criterion here.
	$hydration_technique = $block_type->supports['view']['hydration'] ?? 'idle';

	// The following is a bit hacky. If we stick with this technique, we might
	// want to change apply_block_supports() to accepts a block as its argument.
	$previous_block_to_render = WP_Block_Supports::$block_to_render;
	WP_Block_Supports::$block_to_render = $block;
	$block_props = WP_Block_Supports::get_instance()->apply_block_supports();
	WP_Block_Supports::$block_to_render = $previous_block_to_render;

	// Generate all required wrapper attributes.
	$block_wrapper_attributes =
		sprintf(
			'data-wp-block-type="%1$s" ' .
			'data-wp-block-uses-block-context="%2$s" ' .
			'data-wp-block-provides-block-context="%3$s" ' .
			'data-wp-block-attributes="%4$s" ' .
			'data-wp-block-sourced-attributes="%5$s" ' .
			'data-wp-block-props="%6$s" ' .
			'data-wp-block-hydration="%7$s"',
			esc_attr($block['blockName']),
			esc_attr(json_encode($block_type->uses_context)),
			esc_attr(json_encode($block_type->provides_context)),
			esc_attr(json_encode($attributes)),
			esc_attr(json_encode($sourced_attributes)),
			esc_attr(json_encode($block_props)),
			esc_attr($hydration_technique)
		);

	// The block content comes between two line breaks that seem to be included during block
	// serialization, corresponding to those between the block markup and the block content.
	//
	// They need to be removed here; otherwise, the preact hydration fails.
	$block_content = substr($block_content, 1, -1);

	// Append all wp block attributes after the class attribute containing the block class name.
	// Elements with that class name are supposed to be those with the wrapper attributes.
	//
	// We could use `WP_HTML_Walker` (see https://github.com/WordPress/gutenberg/pull/42485) in the
	// future if that PR is finally merged.

	// This replace is similar to the one used in Gutenberg (see
	// https://github.com/WordPress/gutenberg/blob/1582c723f31ce0f728cd4dcc1af37821342eeaaa/packages/blocks/src/api/serializer.js#L41-L44).
	$block_classname = 'wp-block-' . preg_replace(
		['/\//', '/^core-/'],
		['-', ''],
		$instance->name
	);

	// Be aware that this pattern could not cover some edge cases.
	$class_pattern     = '/class="\s*(?:[\w\s-]\s+)*' . $block_classname . '(?:\s+[\w-]+)*\s*"/';
	$class_replacement = '$0 ' . $block_wrapper_attributes;
	$block_content     = preg_replace( $class_pattern, $class_replacement, $block_content, 1 );

	return $block_content;
}

add_filter('render_block', 'bhe_block_wrapper', 10, 3);
