<?php
defined( 'ABSPATH' ) || exit;

class ACF_DB_Syncer {

    /**
     * Run the sync for the given category→field group pairs.
     *
     * @param array[] $pairs  Each: [ 'cat_id' => int, 'cat_name' => string, 'group_key' => string ]
     * @return array[]  Each result row contains:
     *   'cat_name', 'group_title', 'posts', 'added', 'orphan_keys' (string[])
     */
    public function run( array $pairs ): array {
        $results = [];

        foreach ( $pairs as $pair ) {
            $cat_id    = (int) $pair['cat_id'];
            $cat_name  = $pair['cat_name'];
            $group_key = $pair['group_key'];

            $group = acf_get_field_group( $group_key );
            if ( ! $group ) {
                $results[] = [
                    'cat_name'    => $cat_name,
                    'group_title' => "{$group_key} (not found)",
                    'posts'       => 0,
                    'added'       => 0,
                    'orphan_keys' => [],
                ];
                continue;
            }

            $fields = acf_get_fields( $group_key );
            if ( empty( $fields ) ) {
                $results[] = [
                    'cat_name'    => $cat_name,
                    'group_title' => $group['title'],
                    'posts'       => 0,
                    'added'       => 0,
                    'orphan_keys' => [],
                ];
                continue;
            }

            $leaf_fields     = $this->collect_leaf_fields( $fields );
            $known_meta_keys = array_column( $leaf_fields, 'name' );

            // Get all non-trashed posts in this category, across all post types.
            // Use all registered statuses so frontend-submitted posts with
            // 'pending' / 'draft' / custom statuses are also synced.
            $posts = get_posts( [
                'post_type'      => 'any',
                'post_status'    => $this->all_active_statuses(),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [ [
                    'taxonomy' => 'category',
                    'field'    => 'term_id',
                    'terms'    => $cat_id,
                ] ],
            ] );

            $added       = 0;
            $orphan_keys = [];

            foreach ( $posts as $post_id ) {
                // Add missing fields.
                foreach ( $leaf_fields as $field ) {
                    if ( $this->ensure_field_meta( (int) $post_id, $field ) ) {
                        $added++;
                    }
                }

                // Detect orphan ACF meta keys: postmeta rows whose _reference key points
                // to an ACF field key, but the field name is not in the current field group.
                $all_meta = get_post_meta( (int) $post_id );
                foreach ( $all_meta as $meta_key => $_ ) {
                    // Skip reference rows and non-ACF keys.
                    if ( str_starts_with( $meta_key, '_' ) ) {
                        continue;
                    }
                    $ref = get_post_meta( (int) $post_id, '_' . $meta_key, true );
                    // ACF reference values start with 'field_'.
                    if ( ! $ref || ! str_starts_with( $ref, 'field_' ) ) {
                        continue;
                    }
                    if ( ! in_array( $meta_key, $known_meta_keys, true ) ) {
                        $orphan_keys[ $meta_key ] = true;
                    }
                }
            }

            $results[] = [
                'cat_name'    => $cat_name,
                'group_title' => $group['title'],
                'posts'       => count( $posts ),
                'added'       => $added,
                'orphan_keys' => array_keys( $orphan_keys ),
            ];
        }

        return $results;
    }

    /**
     * Delete orphan postmeta rows (value + ACF reference) for the given keys and category.
     *
     * @param int      $cat_id
     * @param string[] $keys
     * @return int  Number of posts affected.
     */
    public function delete_orphans( int $cat_id, array $keys ): int {
        $posts = get_posts( [
            'post_type'      => 'any',
            'post_status'    => $this->all_active_statuses(),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [ [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $cat_id,
            ] ],
        ] );

        $affected = 0;
        foreach ( $posts as $post_id ) {
            foreach ( $keys as $key ) {
                $key = sanitize_text_field( $key );
                if ( metadata_exists( 'post', (int) $post_id, $key ) ) {
                    delete_post_meta( (int) $post_id, $key );
                    delete_post_meta( (int) $post_id, '_' . $key );
                    $affected++;
                }
            }
        }

        return $affected;
    }

    /**
     * Returns all non-trashed post statuses registered in WordPress,
     * including any added by plugins (e.g. custom listing statuses).
     *
     * @return string[]
     */
    private function all_active_statuses(): array {
        $all = array_keys( $GLOBALS['wp_post_statuses'] ?? [] );
        if ( empty( $all ) ) {
            return [ 'publish', 'pending', 'draft', 'private' ];
        }
        return array_values( array_diff( $all, [ 'trash', 'auto-draft' ] ) );
    }

    /**
     * Recursively collect leaf ACF fields (skipping UI-only containers).
     */
    private function collect_leaf_fields( array $fields ): array {
        $skip_types = [ 'tab', 'accordion', 'message', 'separator' ];
        $leaf       = [];

        foreach ( $fields as $field ) {
            $type = $field['type'] ?? '';

            if ( in_array( $type, $skip_types, true ) ) {
                continue;
            }

            if ( in_array( $type, [ 'group', 'repeater', 'flexible_content' ], true ) ) {
                $leaf[] = $field;
                if ( ! empty( $field['sub_fields'] ) ) {
                    $leaf = array_merge( $leaf, $this->collect_leaf_fields( $field['sub_fields'] ) );
                }
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
     */
    private function ensure_field_meta( int $post_id, array $field ): bool {
        $meta_key = $field['name'];

        if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
            return false;
        }

        add_post_meta( $post_id, $meta_key, '' );
        add_post_meta( $post_id, '_' . $meta_key, $field['key'] );

        return true;
    }
}
