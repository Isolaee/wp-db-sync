<?php
defined( 'ABSPATH' ) || exit;

class ACF_DB_Sync_Admin_Page {

    private const OPTION_KEY = 'acf_db_sync_mappings'; // [ 'cat_slug' => 'group_key', ... ]

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
    }

    public static function add_menu(): void {
        add_management_page(
            'ACF DB Sync',
            'ACF DB Sync',
            'manage_options',
            'acf-db-sync',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'settings' ? 'settings' : 'run';

        echo '<div class="wrap"><h1>ACF DB Sync</h1>';
        self::render_tabs( $tab );

        if ( $tab === 'settings' ) {
            self::render_settings();
        } else {
            self::render_run();
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Tab navigation
    // -------------------------------------------------------------------------

    private static function render_tabs( string $active ): void {
        $base = admin_url( 'tools.php?page=acf-db-sync' );
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
            <a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo $active === 'run' ? 'nav-tab-active' : ''; ?>">Run Sync</a>
            <a href="<?php echo esc_url( $base . '&tab=settings' ); ?>" class="nav-tab <?php echo $active === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
        </nav>
        <?php
    }

    // -------------------------------------------------------------------------
    // Settings tab — map categories to field groups
    // -------------------------------------------------------------------------

    private static function render_settings(): void {
        $error   = '';
        $success = '';

        if ( isset( $_POST['acf_db_sync_settings_nonce'] ) ) {
            if ( ! check_admin_referer( 'acf_db_sync_save_settings', 'acf_db_sync_settings_nonce' ) ) {
                $error = 'Security check failed.';
            } else {
                $raw      = isset( $_POST['mappings'] ) && is_array( $_POST['mappings'] ) ? $_POST['mappings'] : [];
                $mappings = [];
                foreach ( $raw as $cat_id => $group_key ) {
                    $cat_id    = absint( $cat_id );
                    $group_key = sanitize_text_field( $group_key );
                    if ( $cat_id && $group_key ) {
                        $mappings[ $cat_id ] = $group_key;
                    }
                }
                update_option( self::OPTION_KEY, $mappings );
                $success = 'Settings saved.';
            }
        }

        $mappings     = (array) get_option( self::OPTION_KEY, [] );
        $categories   = get_categories( [ 'hide_empty' => false ] );
        $field_groups = acf_get_field_groups();

        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
        if ( $success ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $success ) . '</p></div>';
        }

        ?>
        <p>Map each category to the ACF field group that belongs to it. Only mapped categories will appear on the Run tab.</p>
        <form method="post">
            <?php wp_nonce_field( 'acf_db_sync_save_settings', 'acf_db_sync_settings_nonce' ); ?>
            <table class="widefat" style="max-width:700px;">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>ACF Field Group</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $categories as $cat ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $cat->name ); ?>
                                <span style="color:#888;font-size:12px;">(<?php echo esc_html( $cat->slug ); ?>)</span>
                            </td>
                            <td>
                                <select name="mappings[<?php echo (int) $cat->term_id; ?>]" style="min-width:250px;">
                                    <option value="">— no mapping —</option>
                                    <?php foreach ( $field_groups as $group ) : ?>
                                        <option
                                            value="<?php echo esc_attr( $group['key'] ); ?>"
                                            <?php selected( $mappings[ $cat->term_id ] ?? '', $group['key'] ); ?>
                                        ><?php echo esc_html( $group['title'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:16px;"><?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?></p>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Run tab — pick categories and sync
    // -------------------------------------------------------------------------

    private static function render_run(): void {
        $error          = '';
        $results        = null;
        $delete_message = '';

        $mappings = (array) get_option( self::OPTION_KEY, [] );

        // Only show categories that have a field group mapped.
        $mapped_categories = [];
        foreach ( $mappings as $cat_id => $group_key ) {
            if ( ! $group_key ) {
                continue;
            }
            $cat = get_term( (int) $cat_id, 'category' );
            if ( $cat && ! is_wp_error( $cat ) ) {
                $mapped_categories[ $cat_id ] = $cat;
            }
        }

        // Handle orphan deletion.
        if ( isset( $_POST['acf_db_sync_delete_nonce'] ) ) {
            if ( ! check_admin_referer( 'acf_db_sync_delete_orphans', 'acf_db_sync_delete_nonce' ) ) {
                $error = 'Security check failed.';
            } else {
                $syncer      = new ACF_DB_Syncer();
                $del_cat_id  = absint( $_POST['orphan_cat_id'] ?? 0 );
                $raw_keys    = isset( $_POST['orphan_keys'] ) && is_array( $_POST['orphan_keys'] )
                    ? $_POST['orphan_keys'] : [];
                $keys        = array_map( 'sanitize_text_field', $raw_keys );

                if ( $del_cat_id && ! empty( $keys ) ) {
                    $affected       = $syncer->delete_orphans( $del_cat_id, $keys );
                    $delete_message = sprintf(
                        'Deleted %d orphan meta row(s) across posts in this category.',
                        $affected
                    );
                }
            }
        }

        // Handle sync run.
        if ( isset( $_POST['acf_db_sync_nonce'] ) ) {
            if ( ! check_admin_referer( 'acf_db_sync_run', 'acf_db_sync_nonce' ) ) {
                $error = 'Security check failed.';
            } elseif ( empty( $mapped_categories ) ) {
                $error = 'No category→field group mappings configured. Go to the Settings tab first.';
            } else {
                $raw_cats     = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? $_POST['categories'] : [];
                $selected_ids = array_map( 'absint', $raw_cats );

                if ( empty( $selected_ids ) ) {
                    $error = 'Please select at least one category.';
                } else {
                    $pairs = [];
                    foreach ( $selected_ids as $cat_id ) {
                        if ( isset( $mappings[ $cat_id ] ) && $mappings[ $cat_id ] ) {
                            $cat = $mapped_categories[ $cat_id ] ?? get_term( $cat_id, 'category' );
                            if ( $cat && ! is_wp_error( $cat ) ) {
                                $pairs[] = [
                                    'cat_id'    => $cat_id,
                                    'cat_name'  => $cat->name,
                                    'group_key' => $mappings[ $cat_id ],
                                ];
                            }
                        }
                    }

                    if ( empty( $pairs ) ) {
                        $error = 'None of the selected categories have a field group mapped.';
                    } else {
                        $syncer  = new ACF_DB_Syncer();
                        $results = $syncer->run( $pairs );
                    }
                }
            }
        }

        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
        if ( $delete_message ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $delete_message ) . '</p></div>';
        }

        // Sync results.
        if ( $results !== null ) :
            // Collect rows that have orphans for the delete form below.
            $orphan_rows = array_filter( $results, fn( $r ) => ! empty( $r['orphan_keys'] ) );
            ?>
            <div class="notice notice-success">
                <p><strong>Sync complete.</strong></p>
                <table class="widefat" style="max-width:620px;margin-top:8px;">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Field group</th>
                            <th>Posts processed</th>
                            <th>Meta rows added</th>
                            <th>Orphan fields</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['cat_name'] ); ?></td>
                                <td><?php echo esc_html( $row['group_title'] ); ?></td>
                                <td><?php echo (int) $row['posts']; ?></td>
                                <td><?php echo (int) $row['added']; ?></td>
                                <td>
                                    <?php if ( ! empty( $row['orphan_keys'] ) ) : ?>
                                        <span style="color:#b32d2e;font-weight:600;">
                                            <?php echo count( $row['orphan_keys'] ); ?> found
                                        </span>
                                    <?php else : ?>
                                        <span style="color:#2e7d32;">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( ! empty( $orphan_rows ) ) :
                // Look up cat_id for each orphan row from the pairs we built.
                // Re-derive cat_id by matching cat_name back to mapped_categories.
                $cat_id_by_name = [];
                foreach ( $mapped_categories as $cid => $cobj ) {
                    $cat_id_by_name[ $cobj->name ] = $cid;
                }
                ?>
                <div class="notice notice-warning" style="padding-bottom:12px;">
                    <p><strong>Orphan ACF fields detected.</strong>
                    These meta keys exist on posts but are not part of the current field group.
                    Review them below and delete if they are no longer needed.</p>

                    <?php foreach ( $orphan_rows as $row ) :
                        $cat_id = $cat_id_by_name[ $row['cat_name'] ] ?? 0;
                        if ( ! $cat_id ) continue;
                        ?>
                        <h4 style="margin:16px 0 6px;"><?php echo esc_html( $row['cat_name'] ); ?> — <?php echo esc_html( $row['group_title'] ); ?></h4>
                        <form method="post" style="margin-bottom:16px;">
                            <?php wp_nonce_field( 'acf_db_sync_delete_orphans', 'acf_db_sync_delete_nonce' ); ?>
                            <input type="hidden" name="orphan_cat_id" value="<?php echo (int) $cat_id; ?>">
                            <fieldset style="margin-bottom:8px;">
                                <?php foreach ( $row['orphan_keys'] as $key ) : ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="orphan_keys[]" value="<?php echo esc_attr( $key ); ?>" checked>
                                        <code><?php echo esc_html( $key ); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <?php submit_button( 'Delete selected orphans', 'delete', 'submit', false ); ?>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif;

        if ( empty( $mapped_categories ) ) {
            echo '<p>No categories mapped yet. <a href="' . esc_url( admin_url( 'tools.php?page=acf-db-sync&tab=settings' ) ) . '">Go to Settings</a> to configure mappings.</p>';
            return;
        }
        ?>
        <p>Select which categories to sync. Only posts in the selected category will be updated, using only that category's mapped field group.</p>
        <form method="post">
            <?php wp_nonce_field( 'acf_db_sync_run', 'acf_db_sync_nonce' ); ?>
            <fieldset>
                <?php foreach ( $mapped_categories as $cat_id => $cat ) : ?>
                    <?php $group = acf_get_field_group( $mappings[ $cat_id ] ); ?>
                    <label style="display:block;margin-bottom:8px;">
                        <input type="checkbox" name="categories[]" value="<?php echo (int) $cat_id; ?>">
                        <strong><?php echo esc_html( $cat->name ); ?></strong>
                        <?php if ( $group ) : ?>
                            <span style="color:#888;font-size:12px;">→ <?php echo esc_html( $group['title'] ); ?></span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p style="margin-top:16px;"><?php submit_button( 'Run Sync', 'primary', 'submit', false ); ?></p>
        </form>
        <?php
    }
}
