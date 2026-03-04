<?php
defined( 'ABSPATH' ) || exit;

class ACF_DB_Syncer {

    /**
     * Run the sync for the given post types.
     *
     * @param string[] $post_types
     * @return array<string, array{posts: int, added: int}>
     */
    public function run( array $post_types ): array {
        $results = [];
        foreach ( $post_types as $pt ) {
            $results[ $pt ] = [ 'posts' => 0, 'added' => 0 ];
        }

        $field_groups = acf_get_field_groups();

        foreach ( $field_groups as $group ) {
            // Determine which of the selected post types this group applies to.
            $applicable = $this->get_applicable_post_types( $group, $post_types );
            if ( empty( $applicable ) ) {
                continue;
            }

            // Collect all leaf fields (skip layout/ui-only field types).
            $fields = acf_get_fields( $group['key'] );
            if ( empty( $fields ) ) {
                continue;
            }

            $leaf_fields = $this->collect_leaf_fields( $fields );
            if ( empty( $leaf_fields ) ) {
                continue;
            }

            foreach ( $applicable as $pt ) {
                $posts = get_posts( [
                    'post_type'      => $pt,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ] );

                $results[ $pt ]['posts'] = max( $results[ $pt ]['posts'], count( $posts ) );

                foreach ( $posts as $post_id ) {
                    foreach ( $leaf_fields as $field ) {
                        $added = $this->ensure_field_meta( (int) $post_id, $field );
                        if ( $added ) {
                            $results[ $pt ]['added']++;
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Returns the subset of $selected_post_types that this field group applies to,
     * based on its location rules.
     *
     * If the group has no post_type location rule at all, it is considered applicable
     * to all selected post types.
     *
     * @param array    $group
     * @param string[] $selected_post_types
     * @return string[]
     */
    private function get_applicable_post_types( array $group, array $selected_post_types ): array {
        $location = $group['location'] ?? [];

        // Collect every post type mentioned with operator '==' across all rule sets.
        $required_types = [];
        $has_post_type_rule = false;

        foreach ( $location as $rule_set ) {
            foreach ( $rule_set as $rule ) {
                if ( ( $rule['param'] ?? '' ) === 'post_type' && ( $rule['operator'] ?? '' ) === '==' ) {
                    $has_post_type_rule = true;
                    $required_types[]   = $rule['value'];
                }
            }
        }

        if ( ! $has_post_type_rule ) {
            // No post_type restriction — applicable to all selected types.
            return $selected_post_types;
        }

        return array_values( array_intersect( $selected_post_types, $required_types ) );
    }

    /**
     * Recursively collect leaf ACF fields (skipping UI-only containers).
     *
     * @param array $fields
     * @return array  Each element is an ACF field array with at least 'key' and 'name'.
     */
    private function collect_leaf_fields( array $fields ): array {
        $skip_types = [ 'tab', 'accordion', 'message', 'separator' ];
        $leaf        = [];

        foreach ( $fields as $field ) {
            $type = $field['type'] ?? '';

            if ( in_array( $type, $skip_types, true ) ) {
                continue;
            }

            // Group / repeater / flexible_content fields act as containers.
            // They have their own meta key AND child fields.
            if ( in_array( $type, [ 'group', 'repeater', 'flexible_content' ], true ) ) {
                // Include the container field itself (its meta key stores row count / value).
                $leaf[] = $field;
                // Also recurse into sub_fields.
                if ( ! empty( $field['sub_fields'] ) ) {
                    $leaf = array_merge( $leaf, $this->collect_leaf_fields( $field['sub_fields'] ) );
                }
                // flexible_content layouts.
                if ( ! empty( $field['layouts'] ) ) {
                    foreach ( $field['layouts'] as $layout ) {
                        if ( ! empty( $layout['sub_fields'] ) ) {
                            $leaf = array_merge( $leaf, $this->collect_leaf_fields( $layout['sub_fields'] ) );
                        }
                    }
                }
                continue;
            }

            $leaf[] = $field;
        }

        return $leaf;
    }

    /**
     * Ensure the postmeta row exists for a field on a post.
     * Returns true if a new row was inserted.
     *
     * @param int   $post_id
     * @param array $field  ACF field definition array.
     * @return bool
     */
    private function ensure_field_meta( int $post_id, array $field ): bool {
        $meta_key = $field['name'];

        if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
            return false;
        }

        // Add the value row and ACF's hidden reference row (_fieldname => field_key).
        add_post_meta( $post_id, $meta_key, '' );
        add_post_meta( $post_id, '_' . $meta_key, $field['key'] );

        return true;
    }
}
