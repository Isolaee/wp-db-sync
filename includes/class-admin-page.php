<?php
defined( 'ABSPATH' ) || exit;

class ACF_DB_Sync_Admin_Page {

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

        $results = null;
        $error   = '';

        if ( isset( $_POST['acf_db_sync_nonce'] ) ) {
            if ( ! check_admin_referer( 'acf_db_sync_run', 'acf_db_sync_nonce' ) ) {
                $error = 'Security check failed.';
            } else {
                $raw_types      = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
                    ? $_POST['post_types']
                    : [];
                $selected_types = array_map( 'sanitize_key', $raw_types );

                if ( empty( $selected_types ) ) {
                    $error = 'Please select at least one post type.';
                } else {
                    $syncer  = new ACF_DB_Syncer();
                    $results = $syncer->run( $selected_types );
                }
            }
        }

        // Get all public post types for the checkbox list.
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        ?>
        <div class="wrap">
            <h1>ACF DB Sync</h1>
            <p>Adds missing postmeta rows (with an empty value) to existing posts for every ACF field in their assigned field groups.</p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <?php if ( $results !== null ) : ?>
                <div class="notice notice-success">
                    <p><strong>Sync complete.</strong></p>
                    <table class="widefat" style="max-width:500px;margin-top:8px;">
                        <thead>
                            <tr>
                                <th>Post type</th>
                                <th>Posts processed</th>
                                <th>Meta rows added</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $results as $pt => $counts ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $pt ); ?></td>
                                    <td><?php echo (int) $counts['posts']; ?></td>
                                    <td><?php echo (int) $counts['added']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'acf_db_sync_run', 'acf_db_sync_nonce' ); ?>

                <h2>Select post types to sync</h2>
                <fieldset>
                    <?php foreach ( $post_types as $pt ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input
                                type="checkbox"
                                name="post_types[]"
                                value="<?php echo esc_attr( $pt->name ); ?>"
                                <?php if ( $results !== null && in_array( $pt->name, array_keys( $results ), true ) ) : ?>checked<?php endif; ?>
                            >
                            <?php echo esc_html( $pt->label ); ?>
                            <span style="color:#888;font-size:12px;">(<?php echo esc_html( $pt->name ); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <p style="margin-top:16px;">
                    <?php submit_button( 'Run Sync', 'primary', 'submit', false ); ?>
                </p>
            </form>
        </div>
        <?php
    }
}
