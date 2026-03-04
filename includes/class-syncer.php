<?php
defined( 'ABSPATH' ) || exit;

class ACF_DB_Syncer {

    /**
     * Run the sync for the given category→field group pairs.
     *
     * @param array[] $pairs  Each: [ 'cat_id' => int, 'cat_slug' => string, 'cat_name' => string, 'group_key' => string ]
     * @return array[]  Each: [ 'cat_name' => string, 'group_title' => string, 'posts' => int, 'added' => int ]
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
                    'group_title' => $group_key . ' (not found)',
                    'posts'       => 0,
                    'added'       => 0,
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
                ];
                continue;
            }

            $leaf_fields = $this->collect_leaf_fields( $fields );

            // Get all published posts in this category, across all post types.
            $posts = get_posts( [
                'post_type'      => 'any',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [ [
                    'taxonomy' => 'category',
                    'field'    => 'term_id',
                    'terms'    => $cat_id,
                ] ],
            ] );

            $added = 0;
            foreach ( $posts as $post_id ) {
                foreach ( $leaf_fields as $field ) {
                    if ( $this->ensure_field_meta( (int) $post_id, $field ) ) {
                        $added++;
                    }
                }
            }

            $results[] = [
                'cat_name'    => $cat_name,
                'group_title' => $group['title'],
                'posts'       => count( $posts ),
                'added'       => $added,
            ];
        }

        return $results;
    }

    /**
     * Recursively collect leaf ACF fields (skipping UI-only containers).
     *
     * @param array $fields
     * @return array
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
     *
     * @param int   $post_id
     * @param array $field
     * @return bool
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
