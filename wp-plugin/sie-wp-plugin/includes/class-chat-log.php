<?php
/**
 * SIE Chat Log — query/response logging for evaluation
 *
 * Creates a custom DB table to store every chat interaction:
 * query, response, sources retrieved, confidence scores, user feedback.
 *
 * Admin UI at Tools → SIE Chat Log for reviewing and exporting.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Chat_Log {

    const TABLE_VERSION = '1.0';

    public function init() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'rest_api_init', [ $this, 'register_feedback_route' ] );
    }

    /**
     * Create or update the log table. Called on plugin activation.
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'sie_chat_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id         BIGINT UNSIGNED DEFAULT 0,
            query           TEXT NOT NULL,
            response         TEXT NOT NULL,
            provider        VARCHAR(20) NOT NULL DEFAULT '',
            model           VARCHAR(60) NOT NULL DEFAULT '',
            sources         TEXT DEFAULT NULL,
            top_score       FLOAT DEFAULT NULL,
            confidence      ENUM('high','low','none') DEFAULT 'high',
            feedback        ENUM('positive','negative') DEFAULT NULL,
            feedback_note   TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_created (created_at),
            KEY idx_confidence (confidence),
            KEY idx_feedback (feedback)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'sie_chat_log_db_version', self::TABLE_VERSION );
    }

    /**
     * Log a chat interaction.
     */
    public static function log( array $data ) {
        if ( get_option( 'sie_enable_logging', '1' ) !== '1' ) return;

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'sie_chat_log',
            [
                'user_id'    => get_current_user_id(),
                'query'      => $data['query']      ?? '',
                'response'   => $data['response']    ?? '',
                'provider'   => $data['provider']    ?? '',
                'model'      => $data['model']       ?? '',
                'sources'    => $data['sources']     ?? null,
                'top_score'  => $data['top_score']   ?? null,
                'confidence' => $data['confidence']  ?? 'high',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s' ]
        );

        return $wpdb->insert_id;
    }

    // -------------------------------------------------------------------------
    // Feedback endpoint — POST /wp-json/sie/v1/chat-feedback
    // -------------------------------------------------------------------------

    public function register_feedback_route() {
        register_rest_route( 'sie/v1', '/chat-feedback', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_feedback' ],
            'permission_callback' => function ( WP_REST_Request $request ) {
                $nonce = $request->get_header( 'X-WP-Nonce' );
                return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
            },
            'show_in_index'       => false,
            'args' => [
                'log_id'   => [ 'required' => true, 'type' => 'integer' ],
                'feedback' => [ 'required' => true, 'type' => 'string', 'enum' => [ 'positive', 'negative' ] ],
                'note'     => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );
    }

    public function handle_feedback( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sie_chat_log';

        $updated = $wpdb->update(
            $table,
            [
                'feedback'      => sanitize_text_field( $request['feedback'] ),
                'feedback_note' => sanitize_textarea_field( $request['note'] ),
            ],
            [ 'id' => absint( $request['log_id'] ) ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return new WP_Error( 'sie_feedback_error', 'Could not save feedback.', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    // -------------------------------------------------------------------------
    // Admin page — Tools → SIE Chat Log
    // -------------------------------------------------------------------------

    public function add_menu() {
        add_management_page(
            'SIE Chat Log',
            'SIE Chat Log',
            'manage_options',
            'sie-chat-log',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'sie_chat_log';

        // Filters
        $filter_confidence = isset( $_GET['confidence'] ) ? sanitize_text_field( $_GET['confidence'] ) : '';
        $filter_feedback   = isset( $_GET['feedback'] )   ? sanitize_text_field( $_GET['feedback'] )   : '';

        $where = [];
        $params = [];
        if ( $filter_confidence ) {
            $where[]  = 'confidence = %s';
            $params[] = $filter_confidence;
        }
        if ( $filter_feedback ) {
            $where[]  = 'feedback = %s';
            $params[] = $filter_feedback;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page = 25;
        $offset   = ( $page - 1 ) * $per_page;

        $count_query = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $data_query  = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        if ( $params ) {
            $total = $wpdb->get_var( $wpdb->prepare( $count_query, ...$params ) );
            $rows  = $wpdb->get_results( $wpdb->prepare( $data_query, ...array_merge( $params, [ $per_page, $offset ] ) ) );
        } else {
            $total = $wpdb->get_var( $count_query );
            $rows  = $wpdb->get_results( $wpdb->prepare( $data_query, $per_page, $offset ) );
        }

        $total_pages = ceil( $total / $per_page );

        // Stats
        $stats = $wpdb->get_row( "SELECT
            COUNT(*) as total,
            SUM(confidence = 'low') as low_conf,
            SUM(confidence = 'none') as no_conf,
            SUM(feedback = 'positive') as thumbs_up,
            SUM(feedback = 'negative') as thumbs_down
        FROM {$table}" );

        ?>
        <div class="wrap">
            <h1>SIE Chat Log</h1>

            <div style="display:flex;gap:20px;margin-bottom:20px;">
                <div class="card" style="padding:10px 15px;margin:0;">
                    <strong><?php echo intval( $stats->total ); ?></strong> total queries
                </div>
                <div class="card" style="padding:10px 15px;margin:0;">
                    <strong style="color:green;"><?php echo intval( $stats->thumbs_up ); ?></strong> positive
                    &nbsp;/&nbsp;
                    <strong style="color:red;"><?php echo intval( $stats->thumbs_down ); ?></strong> negative
                </div>
                <div class="card" style="padding:10px 15px;margin:0;">
                    <strong style="color:orange;"><?php echo intval( $stats->low_conf ); ?></strong> low confidence
                    &nbsp;/&nbsp;
                    <strong style="color:red;"><?php echo intval( $stats->no_conf ); ?></strong> no match
                </div>
            </div>

            <form method="get" style="margin-bottom:15px;">
                <input type="hidden" name="page" value="sie-chat-log" />
                <select name="confidence">
                    <option value="">All confidence</option>
                    <option value="high" <?php selected( $filter_confidence, 'high' ); ?>>High</option>
                    <option value="low"  <?php selected( $filter_confidence, 'low'  ); ?>>Low</option>
                    <option value="none" <?php selected( $filter_confidence, 'none' ); ?>>No match</option>
                </select>
                <select name="feedback">
                    <option value="">All feedback</option>
                    <option value="positive" <?php selected( $filter_feedback, 'positive' ); ?>>Positive</option>
                    <option value="negative" <?php selected( $filter_feedback, 'negative' ); ?>>Negative</option>
                </select>
                <?php submit_button( 'Filter', 'secondary', '', false ); ?>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Query</th>
                        <th>Response</th>
                        <th>Model</th>
                        <th>Score</th>
                        <th>Conf.</th>
                        <th>Feedback</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $rows ) : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html( $row->created_at ); ?></td>
                        <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;"
                            title="<?php echo esc_attr( $row->query ); ?>">
                            <?php echo esc_html( wp_trim_words( $row->query, 15 ) ); ?>
                        </td>
                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;"
                            title="<?php echo esc_attr( $row->response ); ?>">
                            <?php echo esc_html( wp_trim_words( $row->response, 20 ) ); ?>
                        </td>
                        <td><?php echo esc_html( $row->model ); ?></td>
                        <td><?php echo $row->top_score !== null ? number_format( $row->top_score, 2 ) : '—'; ?></td>
                        <td>
                            <?php
                            $conf_colors = [ 'high' => 'green', 'low' => 'orange', 'none' => 'red' ];
                            $color = $conf_colors[ $row->confidence ] ?? 'gray';
                            echo '<span style="color:' . $color . ';">' . esc_html( $row->confidence ) . '</span>';
                            ?>
                        </td>
                        <td>
                            <?php
                            if ( $row->feedback === 'positive' ) echo '<span style="color:green;">+</span>';
                            elseif ( $row->feedback === 'negative' ) echo '<span style="color:red;">-</span>';
                            else echo '—';
                            if ( $row->feedback_note ) echo '<br><small>' . esc_html( wp_trim_words( $row->feedback_note, 10 ) ) . '</small>';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="7">No log entries yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $total_pages,
                    ] );
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
