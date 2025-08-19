<?php
if (!defined('ABSPATH')) {
    exit;
}

class CWR_Affiliates_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => __('Affiliate', 'cwr'),
            'plural'   => __('Affiliates', 'cwr'),
            'ajax'     => false,
        ]);
        error_log('CWR Affiliates: Initialized CWR_Affiliates_Table');
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'user_id'       => __('User ID', 'cwr'),
            'username'      => __('Username', 'cwr'),
            'email'         => __('Email', 'cwr'),
            'display_name'  => __('Display Name', 'cwr'),
            'referral_code' => __('Referral Code', 'cwr'),
            'roles'         => __('Roles', 'cwr')
        ];
    }

    public function get_sortable_columns() {
        return [
            'user_id'      => ['user_id', false],
            'username'     => ['user_login', false],
            'email'        => ['user_email', false],
            'display_name' => ['display_name', false]
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'user_id':
                return esc_html($item->ID);
            case 'username':
                return esc_html($item->user_login);
            case 'email':
                return esc_html($item->user_email);
            case 'display_name':
                return esc_html($item->display_name);
            case 'referral_code':
                $code = cwr_get_user_referral_code($item->ID);
                return esc_html($code === 'error-generating-code' ? __('N/A', 'cwr') : $code);
            case 'roles':
                $user = new WP_User($item->ID);
                return esc_html(implode(', ', $user->roles));
            default:
                return '';
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="affiliate[]" value="%s" />', esc_attr($item->ID));
    }

    public function prepare_items() {
        global $wpdb;

        $users_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
        $meta_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key = 'wp_capabilities'");
        error_log('CWR Affiliates: wp_users count: ' . $users_count . ', wp_usermeta wp_capabilities count: ' . $meta_count);

        $per_page = $this->get_items_per_page('affiliates_per_page', 20);
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $orderby = isset($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], array_keys($this->get_sortable_columns())) ? sanitize_text_field($_REQUEST['orderby']) : 'user_id';
        $order = isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC']) ? strtoupper($_REQUEST['order']) : 'ASC';

        $args = [
            'role__in' => ['customer'],
            'number' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => $order,
            'meta_query' => [
                [
                    'key' => 'wp_capabilities',
                    'value' => '"customer"',
                    'compare' => 'LIKE'
                ]
            ]
        ];

        if ($search) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email'];
        }

        error_log('CWR Affiliates: Running WP_User_Query with args: ' . json_encode($args));

        $users = new WP_User_Query($args);
        $this->items = $users->get_results();

        error_log('CWR Affiliates: WP_User_Query SQL: ' . $users->request);

        foreach ($this->items as $user) {
            error_log('CWR Affiliates: Found user ID ' . $user->ID . ', login: ' . $user->user_login . ', email: ' . $user->user_email . ', roles: ' . implode(', ', (array)$user->roles));
        }

        if (empty($this->items)) {
            error_log('CWR Affiliates: No customer users found');
            add_action('admin_notices', function() use ($users_count, $meta_count) {
                $message = '';
                if ($users_count == 0) {
                    $message = __('No users found in wp_users. Please create users.', 'cwr');
                } elseif ($meta_count == 0) {
                    $message = __('No wp_capabilities found in wp_usermeta. Check user roles.', 'cwr');
                } else {
                    $message = __('No users with the customer role found. Check wp_usermeta or create customer users.', 'cwr');
                }
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($message) . '</p></div>';
            });

            $all_users = $wpdb->get_results("SELECT u.ID, u.user_login, u.user_email, m.meta_value AS roles FROM $wpdb->users u LEFT JOIN $wpdb->usermeta m ON u.ID = m.user_id AND m.meta_key = 'wp_capabilities'");
            if ($all_users) {
                add_action('admin_notices', function() use ($all_users) {
                    echo '<div class="notice notice-info is-dismissible"><h3>' . esc_html__('Debug: All Users', 'cwr') . '</h3><table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Roles</th></tr></thead><tbody>';
                    foreach ($all_users as $user) {
                        $roles = maybe_unserialize($user->roles);
                        $roles = is_array($roles) ? implode(', ', array_keys($roles)) : 'None';
                        echo '<tr><td>' . esc_html($user->ID) . '</td><td>' . esc_html($user->user_login) . '</td><td>' . esc_html($user->user_email) . '</td><td>' . esc_html($roles) . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                });
            }
        }

        error_log('CWR Affiliates: Retrieved ' . count($this->items) . ' users for Affiliates table, page ' . $current_page);

        $total_items = $users->get_total();
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    public function get_bulk_actions() {
        return [];
    }

    protected function get_views() {
        return [];
    }

    public function extra_tablenav($which) {
        if ($which === 'top') {
            ?>
            <div class="alignleft actions">
                <form method="get">
                    <input type="hidden" name="page" value="cwr-settings-affiliates">
                    <?php $this->search_box(__('Search Users', 'cwr'), 'search_id'); ?>
                </form>
            </div>
            <?php
        }
    }
}
?>