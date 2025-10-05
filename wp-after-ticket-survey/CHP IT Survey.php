<?php
/**
 * Plugin Name: After Ticket Survey
 * Description: A powerful and flexible WordPress plugin to create and manage after-ticket surveys. Capture submitter details, link survey results directly to tickets, and view all feedback from your WordPress dashboard.
 * Version:     2.17
 * Author:      Philip Edwards & Gemini
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: after-ticket-survey
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Global variables for database table names and version.
 */
global $ats_db_version;
$ats_db_version = '2.16';
global $ats_questions_table_name;
global $ats_dropdown_options_table_name;
global $ats_survey_submissions_table_name;
global $ats_survey_answers_table_name;

$ats_questions_table_name        = 'ats_questions';
$ats_dropdown_options_table_name = 'ats_dropdown_options';
$ats_survey_submissions_table_name = 'ats_survey_submissions';
$ats_survey_answers_table_name   = 'ats_survey_answers';

/**
 * Function to create database tables and pre-populate questions on plugin activation/update.
 */
function ats_install() {
    global $wpdb;
    global $ats_db_version;
    global $ats_questions_table_name;
    global $ats_dropdown_options_table_name;
    global $ats_survey_submissions_table_name;
    global $ats_survey_answers_table_name;

    $charset_collate = $wpdb->get_charset_collate();

    $questions_table         = $wpdb->prefix . $ats_questions_table_name;
    $dropdown_options_table  = $wpdb->prefix . $ats_dropdown_options_table_name;
    $submissions_table       = $wpdb->prefix . $ats_survey_submissions_table_name;
    $answers_table           = $wpdb->prefix . $ats_survey_answers_table_name;

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // Always run dbDelta to ensure tables are created/updated
    $sql_questions = "CREATE TABLE $questions_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        question_text text NOT NULL,
        question_type varchar(50) NOT NULL, -- 'rating', 'short_text', 'long_text', 'dropdown'
        sort_order int(11) DEFAULT 0 NOT NULL,
        is_required tinyint(1) DEFAULT 1 NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_questions );

    $sql_dropdown_options = "CREATE TABLE $dropdown_options_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        question_id bigint(20) NOT NULL,
        option_value varchar(255) NOT NULL,
        sort_order int(11) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta( $sql_dropdown_options );

    $sql_submissions = "CREATE TABLE $submissions_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) DEFAULT 0 NOT NULL,
        submission_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_submissions );

    $sql_answers = "CREATE TABLE $answers_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        submission_id bigint(20) NOT NULL,
        question_id bigint(20) NOT NULL,
        answer_value text,
        PRIMARY KEY  (id),
        KEY submission_id (submission_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta( $sql_answers );

    // Define default questions with explicit initial sort_order
    $default_questions = array(
        array(
            'text' => 'What is your ticket number?',
            'type' => 'short_text',
            'required' => 1,
            'initial_sort_order' => 0,
        ),
        array(
            'text' => 'Who was your technician for this ticket?',
            'type' => 'dropdown',
            'options' => array('Technician A', 'Technician B', 'Technician C', 'Technician D'),
            'required' => 1,
            'initial_sort_order' => 1,
        ),
        array(
            'text' => 'Overall, how would you rate the handling of your issue by the IT department?',
            'type' => 'rating',
            'required' => 1,
            'initial_sort_order' => 2,
        ),
        array(
            'text' => 'Were you helped in a timely manner?',
            'type' => 'rating',
            'required' => 1,
            'initial_sort_order' => 3,
        ),
        array(
            'text' => 'Was your technician helpful?',
            'type' => 'rating',
            'required' => 1,
            'initial_sort_order' => 4,
        ),
        array(
            'text' => 'Was your technician courteous?',
            'type' => 'rating',
            'required' => 1,
            'initial_sort_order' => 5,
        ),
        array(
            'text' => 'Did your technician demonstrate a reasonable understanding of your issue?',
            'type' => 'rating',
            'required' => 1,
            'initial_sort_order' => 6,
        ),
        array(
            'text' => 'Do you feel we could make an improvement, or have concerns about how your ticket was handled?',
            'type' => 'long_text',
            'required' => 0, // This question is optional
            'initial_sort_order' => 7,
        ),
    );

    // Get current DB version stored in options
    $installed_db_version = get_option( 'ats_db_version' );

    // Condition to run pre-population:
    // 1. If the table is completely empty (first install or manual clear)
    // 2. If the plugin version has been updated (to add new default questions if any)
    $table_is_empty = ( $wpdb->get_var( "SELECT COUNT(*) FROM {$questions_table}" ) == 0 );

    if ( $table_is_empty || version_compare( $installed_db_version, $ats_db_version, '<' ) ) {
        foreach ( $default_questions as $q_data ) {
            // Check if question already exists by its text to prevent duplicates on upgrade/re-activation
            $existing_question_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$questions_table} WHERE question_text = %s",
                $q_data['text']
            ) );

            if ( ! $existing_question_id ) {
                // Insert question if it doesn't exist
                $wpdb->insert(
                    $questions_table,
                    array(
                        'question_text' => $q_data['text'],
                        'question_type' => $q_data['type'],
                        'sort_order'    => $q_data['initial_sort_order'],
                        'is_required'   => $q_data['required'],
                    ),
                    array( '%s', '%s', '%d', '%d' )
                );
                $question_id = $wpdb->insert_id;

                // Insert dropdown options if applicable
                if ( $q_data['type'] === 'dropdown' && ! empty( $q_data['options'] ) ) {
                    $option_sort_order = 0;
                    foreach ( $q_data['options'] as $option_value ) {
                        $wpdb->insert(
                            $dropdown_options_table,
                            array(
                                'question_id'  => $question_id,
                                'option_value' => $option_value,
                                'sort_order'   => $option_sort_order++,
                            ),
                            array( '%d', '%s', '%d' )
                        );
                    }
                }
            }
        }
    }

    // Always re-index questions on activation/update to ensure consistent sort_order
    ats_reindex_questions_sort_order();

    // Always update the database version in options
    update_option( 'ats_db_version', $ats_db_version );
}
register_activation_hook( __FILE__, 'ats_install' );

/**
 * Enqueue custom CSS for styling the survey form on the frontend.
 */
function ats_enqueue_frontend_styles() {
    // Enqueue for frontend
    wp_enqueue_style( 'ats-survey-frontend-styles', plugin_dir_url( __FILE__ ) . 'ats-survey-frontend-styles.css', array(), '2.3' );
    // Conditionally add inline style for body background if the shortcode is present
    if ( is_singular() && has_shortcode( get_post()->post_content, 'after_ticket_survey' ) ) {
        $options = get_option( 'ats_survey_options' );
        $background_color = isset( $options['background_color'] ) ? $options['background_color'] : '#c0d7e5';
        wp_add_inline_style( 'ats-survey-frontend-styles', 'body { background-color: ' . esc_attr( $background_color ) . ' !important; }' );
    }
}
add_action( 'wp_enqueue_scripts', 'ats_enqueue_frontend_styles' );

/**
 * Enqueue custom CSS and JS for the admin area.
 */
function ats_enqueue_admin_scripts($hook_suffix) {
    // Enqueue global admin styles
    wp_enqueue_style( 'ats-survey-admin-styles', plugin_dir_url( __FILE__ ) . 'ats-survey-admin-styles.css', array(), '2.3' );

    // The hook for our settings page is after-ticket-survey_page_ats-survey-settings.
    // Only load the color picker assets on this specific page.
    if ( 'after-ticket-survey_page_ats-survey-settings' === $hook_suffix ) {
        // Enqueue the color picker styles and scripts
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script(
            'ats-color-picker',
            plugin_dir_url( __FILE__ ) . 'ats-color-picker.js',
            array( 'wp-color-picker' ),
            '1.0.0',
            true
        );
    }
}
add_action( 'admin_enqueue_scripts', 'ats_enqueue_admin_scripts' );


/**
 * Shortcode to display the after-ticket survey form.
 * Usage: [after_ticket_survey]
 */
function ats_survey_shortcode() {
    ob_start(); // Start output buffering.
    global $wpdb;
    global $ats_questions_table_name;
    global $ats_dropdown_options_table_name;
    global $ats_survey_submissions_table_name;
    global $ats_survey_answers_table_name;

    $questions_table         = $wpdb->prefix . $ats_questions_table_name;
    $dropdown_options_table  = $wpdb->prefix . $ats_dropdown_options_table_name;
    $submissions_table       = $wpdb->prefix . $ats_survey_submissions_table_name;
    $answers_table           = $wpdb->prefix . $ats_survey_answers_table_name;

    // Handle form submission on the frontend only
    if ( ! is_admin() && isset( $_POST['ats_submit_survey'] ) && isset( $_POST['ats_survey_nonce'] ) && wp_verify_nonce( $_POST['ats_survey_nonce'], 'ats_survey_form_nonce' ) ) {
        // Fetch all active questions to validate and save
        $questions = $wpdb->get_results( "SELECT id, question_type, is_required FROM {$questions_table} ORDER BY sort_order ASC", ARRAY_A );

        // Get current user, assuming they are always logged in via SSO
        $current_user = wp_get_current_user();
        $user_id      = $current_user->ID;


        // Insert new submission record
        $wpdb->insert(
            $submissions_table,
            array(
                'user_id'         => $user_id,
                'submission_date' => current_time( 'mysql' ),
            ),
            array(
                '%d', // user_id
                '%s'  // submission_date
            )
        );
        $submission_id = $wpdb->insert_id;

        if ( $submission_id ) {
            foreach ( $questions as $question ) {
                $question_id = $question['id'];
                $input_name  = 'ats_q_' . $question_id;
                $answer_value = '';

                if ( isset( $_POST[ $input_name ] ) ) {
                    if ( $question['question_type'] === 'long_text' ) {
                        $answer_value = sanitize_textarea_field( $_POST[ $input_name ] );
                    } else {
                        $answer_value = sanitize_text_field( $_POST[ $input_name ] );
                    }
                } elseif ( $question['is_required'] && empty( $answer_value ) ) {
                    // Handle required fields not submitted (e.g., show error, skip, etc.)
                    // For now, we'll just log an error or skip. In a real app, you'd show user feedback.
                    error_log( "ATS Survey: Required question ID $question_id was not answered." );
                    continue;
                }

                // Insert answer
                $wpdb->insert(
                    $answers_table,
                    array(
                        'submission_id' => $submission_id,
                        'question_id'   => $question_id,
                        'answer_value'  => $answer_value,
                    ),
                    array( '%d', '%d', '%s' )
                );
            }
            // Updated success message
            echo '<div class="ats-success-message">Thank you for completing our survey! Your feedback is invaluable and helps us improve our services.</div>';
        } else {
            echo '<div class="ats-error-message">There was an error submitting your survey. Please try again.</div>';
        }
    } else {
        // Get survey options
        $survey_options = get_option( 'ats_survey_options' );
        $ticket_question_id = isset( $survey_options['ticket_question_id'] ) ? (int) $survey_options['ticket_question_id'] : 0;
        $technician_question_id = isset( $survey_options['technician_question_id'] ) ? (int) $survey_options['technician_question_id'] : 0;

        // Get ticket_id from URL if present
        $prefill_ticket_id = isset($_GET['ticket_id']) ? sanitize_text_field($_GET['ticket_id']) : '';
        // Get tech name from URL if present
        $prefill_tech_name = isset($_GET['tech']) ? sanitize_text_field($_GET['tech']) : '';


        // Display the form.
        $questions = $wpdb->get_results( "SELECT id, question_text, question_type, is_required FROM {$questions_table} ORDER BY sort_order ASC", ARRAY_A );

        if ( empty( $questions ) ) {
            return '<p class="ats-no-questions-message">No survey questions configured yet. Please contact the administrator.</p>';
        }

        $question_number = 1; // Initialize question counter
        ?>
        <div class="ats-survey-container">
            <?php /* Removed: <h2 class="ats-main-title">After Ticket Survey</h2> */ ?>
            <p class="ats-intro-text">We are committed to providing excellent IT support. Your feedback helps us assess our performance and identify areas for improvement.</p>

            <form method="post" class="ats-form">
                <?php wp_nonce_field( 'ats_survey_form_nonce', 'ats_survey_nonce' ); ?>

                <?php foreach ( $questions as $question ) :
                    $input_id = 'ats_q_' . $question['id'];
                    $input_name = 'ats_q_' . $question['id'];
                    $required_attr = $question['is_required'] ? 'required' : '';
                    $required_label = $question['is_required'] ? '<span class="ats-required-label">*</span>' : '';
                    $input_value = ''; // Initialize input value
                ?>
                    <div class="ats-form-group">
                        <label for="<?php echo esc_attr( $input_id ); ?>" class="ats-label">
                            <?php echo $question_number++; ?>. <?php echo esc_html( $question['question_text'] ); ?> <?php echo $required_label; ?>
                        </label>

                        <?php if ( $question['question_type'] === 'short_text' ) :
                            // Check if this is the ticket number question and prefill
                            if ( $question['id'] == $ticket_question_id && ! empty( $prefill_ticket_id ) ) {
                                $input_value = esc_attr( $prefill_ticket_id );
                            }
                        ?>
                            <input type="text" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="ats-input ats-short-text" value="<?php echo $input_value; ?>" <?php echo $required_attr; ?>>

                        <?php elseif ( $question['question_type'] === 'long_text' ) : ?>
                            <textarea id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" rows="4" class="ats-input ats-long-text" <?php echo $required_attr; ?>></textarea>

                        <?php elseif ( $question['question_type'] === 'rating' ) : ?>
                            <div class="ats-rating-options">
                                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                    <label class="ats-radio-label">
                                        <input type="radio" id="<?php echo esc_attr( $input_id . '_' . $i ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $i ); ?>" class="ats-radio-input" <?php echo $required_attr; ?>>
                                        <span class="ats-radio-text"><?php echo esc_html( $i ); ?></span>
                                    </label>
                                <?php endfor; ?>
                                <span class="ats-rating-guide">(1 = Poor, 5 = Excellent)</span>
                            </div>

                        <?php elseif ( $question['question_type'] === 'dropdown' ) :
                            $options = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$dropdown_options_table} WHERE question_id = %d ORDER BY sort_order ASC", $question['id'] ), ARRAY_A );
                            $selected_attr = '';
                        ?>
                            <select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="ats-input ats-dropdown" <?php echo $required_attr; ?>>
                                <option value="">-- Select --</option>
                                <?php foreach ( $options as $option ) :
                                    // Check if this is the technician question and prefill
                                    if ( $question['id'] == $technician_question_id && ! empty( $prefill_tech_name ) ) {
                                        if ( strtolower( $option['option_value'] ) === strtolower( $prefill_tech_name ) ) {
                                            $selected_attr = 'selected';
                                        } else {
                                            $selected_attr = '';
                                        }
                                    }
                                ?>
                                    <option value="<?php echo esc_attr( $option['option_value'] ); ?>" <?php echo $selected_attr; ?>><?php echo esc_html( $option['option_value'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" name="ats_submit_survey" class="ats-submit-button">
                    Submit Survey
                </button>
            </form>
        </div>
        <?php
    }

    return ob_get_clean(); // Return the buffered content.
}
add_shortcode( 'after_ticket_survey', 'ats_survey_shortcode' );

/**
 * Add a link to the plugin settings page on the plugins list.
 */
function ats_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=ats-manage-questions' ) . '">' . __( 'Manage Questions', 'after-ticket-survey' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ats_add_settings_link' );

/**
 * Add admin menu items.
 */
function ats_admin_menu() {
    // Top-level menu for the plugin
    add_menu_page(
        'After Ticket Survey',
        'After Ticket Survey',
        'manage_options', // Admin-level access
        'ats-survey-main',
        'ats_display_main_admin_page',
        'dashicons-feedback',
        80
    );

    // Submenu for managing questions
    add_submenu_page(
        'ats-survey-main',
        'Manage Survey Questions',
        'Manage Questions',
        'manage_options', // Admin-level access
        'ats-manage-questions',
        'ats_display_manage_questions_page'
    );

    // Submenu for viewing results
    add_submenu_page(
        'ats-survey-main',
        'View Survey Results',
        'View Results',
        'manage_options', // Admin-level access
        'ats-view-results',
        'ats_display_view_results_page'
    );

    // New submenu for managing submissions
    add_submenu_page(
        'ats-survey-main',
        'Manage Survey Submissions',
        'Manage Submissions',
        'manage_options', // Admin-level access
        'ats-manage-submissions',
        'ats_display_manage_submissions_page'
    );

    // Add new submenu for settings
    add_submenu_page(
        'ats-survey-main',
        'After Ticket Survey Settings',
        'Settings',
        'manage_options',
        'ats-survey-settings',
        'ats_display_settings_page'
    );
}
add_action( 'admin_menu', 'ats_admin_menu' );

/**
 * Add admin notices for success/error messages.
 */
function ats_admin_notices() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'ats-manage-questions' ) {
        if ( isset( $_GET['message'] ) ) {
            $message = sanitize_text_field( $_GET['message'] );
            if ( $message === 'added' ) {
                echo '<div class="notice notice-success is-dismissible"><p>Question added successfully!</p></div>';
            } elseif ( $message === 'updated' ) {
                echo '<div class="notice notice-success is-dismissible"><p>Question updated successfully!</p></div>';
            } elseif ( $message === 'deleted' ) {
                echo '<div class="notice notice-success is-dismissible"><p>Question deleted successfully!</p></div>';
            } elseif ( $message === 'error' ) {
                echo '<div class="notice notice-error is-dismissible"><p>An error occurred. Please try again.</p></div>';
            }
        }
    }
    // New admin notice for the manage submissions page
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'ats-manage-submissions' ) {
        if ( isset( $_GET['message'] ) ) {
            $message = sanitize_text_field( $_GET['message'] );
            if ( $message === 'submissions_deleted' ) {
                echo '<div class="notice notice-success is-dismissible"><p>Selected submissions deleted successfully!</p></div>';
            } elseif ( $message === 'error' ) {
                echo '<div class="notice notice-error is-dismissible"><p>An error occurred. Please try again.</p></div>';
            }
        }
    }
}
add_action( 'admin_notices', 'ats_admin_notices' );


/**
 * Main admin page content for the plugin.
 */
function ats_display_main_admin_page() {
    ?>
    <div class="wrap">
        <h1 class="ats-admin-main-title">Welcome to the After Ticket Survey Plugin!</h1>
        <p class="ats-admin-intro-text">This plugin allows you to easily create, customize, and manage after-ticket surveys to gather valuable feedback from your users.</p>

        <h2 class="ats-admin-subtitle">How to Use This Plugin:</h2>

        <div class="ats-admin-section">
            <h3>1. Display the Survey on a Page</h3>
            <p>To show the survey form on any page or post on your website, simply add the following shortcode to the content editor:</p>
            <pre><code>[after_ticket_survey]</code></pre>
            <p>Once you add this, the survey form will appear on that page for your users to fill out.</p>
        </div>

        <div class="ats-admin-section">
            <h3>2. Manage Your Survey Questions</h3>
            <p>You have full control over the questions in your survey. To add new questions, edit existing ones, or remove questions:</p>
            <ol>
                <li>Go to **After Ticket Survey &rarr; Manage Questions** in your WordPress admin sidebar.</li>
                <li>Here, you'll see a list of all your current survey questions.</li>
                <li>Use the "Add New Question" form to create new questions. You can choose from different types:
                    <ul>
                        <li>**Short Text:** For brief answers like a ticket number or a single word.</li>
                        <li>**Long Text:** For detailed feedback or comments.</li>
                        <li>**Rating (1-5):** For questions requiring a numerical rating (e.g., satisfaction level).</li>
                        <li>**Dropdown:** For questions with predefined options, like a list of technicians.</li>
                    </ul>
                </li>
                <li>For "Dropdown" questions, remember to enter your options separated by commas (e.g., "Option 1, Option 2").</li>
                <li>You can also **Edit** or **Delete** existing questions using the buttons next to each question in the table.</li>
            </ol>
        </div>

        <div class="ats-admin-section">
            <h3>3. View Survey Results</h3>
            <p>Once users start submitting surveys, you can view all the collected feedback:</p>
            <ol>
                <li>Go to **After Ticket Survey &rarr; View Results** in your WordPress admin sidebar.</li>
                <li>This page will display a table with all survey submissions, showing each user's answers to your questions.</li>
                <li>*(Note: The "View Results" page is currently a basic display. Future updates may include advanced filtering and export options.)*</li>
            </ol>
        </div>

        <div class="ats-admin-section">
            <h3>4. Configure Your Settings</h3>
            <p>The settings page allows you to customize how the plugin works to better fit your needs:</p>
            <ol>
                <li>Go to **After Ticket Survey &rarr; Settings** in your WordPress admin sidebar.</li>
                <li>Here, you can configure the following options:
                    <ul>
                        <li><strong>Survey Page Background Color:</strong> Change the background color of the survey page using an interactive color picker to match your site's theme.</li>
                        <li><strong>Ticket Number Question:</strong> Tell the plugin exactly which question asks for the ticket number. This makes the link from the results page to your ticketing system reliable, even if you change the question's text.</li>
                        <li><strong>Technician Question:</strong> Specify which "Dropdown" type question is used for technicians. This allows you to pre-fill the technician's name in the survey by adding it to the survey URL.</li>
                        <li><strong>Ticket System Base URL:</strong> Set the base URL for your ticketing system. The plugin will append the ticket ID to this URL to create a direct link to the ticket from the "View Results" page.</li>
                    </ul>
                </li>
            </ol>
        </div>

        <p class="ats-admin-footer">Thank you for using the After Ticket Survey plugin!</p>
    </div>
    <?php
}

/**
 * Callback function for the Manage Questions admin page.
 * This now displays existing questions and a form to add new ones.
 */
function ats_display_manage_questions_page() {
    global $wpdb;
    global $ats_questions_table_name;
    global $ats_dropdown_options_table_name;

    $questions_table        = $wpdb->prefix . $ats_questions_table_name;
    $dropdown_options_table = $wpdb->prefix . $ats_dropdown_options_table_name;

    // Determine if we are editing a question
    $editing_question = null;
    if ( isset( $_GET['ats_action'] ) && $_GET['ats_action'] === 'edit_question' && isset( $_GET['question_id'] ) ) {
        $question_id = intval( $_GET['question_id'] );
        $editing_question = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$questions_table} WHERE id = %d", $question_id ), ARRAY_A );
        if ( $editing_question && $editing_question['question_type'] === 'dropdown' ) {
            $options = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$dropdown_options_table} WHERE question_id = %d ORDER BY sort_order ASC", $question_id ), ARRAY_A );
            $editing_question['options_str'] = implode(', ', array_column($options, 'option_value'));
        }
    }

    // Fetch existing questions
    $questions = $wpdb->get_results( "SELECT * FROM {$questions_table} ORDER BY sort_order ASC", ARRAY_A );
    ?>
    <div class="wrap">
        <h1 class="ats-admin-main-title">Manage Survey Questions</h1>

        <h2 class="ats-admin-subtitle">Existing Questions</h2>
        <?php if ( $questions ) : ?>
            <table class="wp-list-table widefat fixed striped ats-admin-table">
                <thead>
                    <tr>
                        <th class="manage-column">Order</th>
                        <th class="manage-column">Question Text</th>
                        <th class="manage-column">Type</th>
                        <th class="manage-column">Required</th>
                        <th class="manage-column">Options (for Dropdown)</th>
                        <th class="manage-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $questions as $question ) : ?>
                        <tr>
                            <td><?php echo esc_html( $question['sort_order'] ); ?></td>
                            <td><?php echo esc_html( $question['question_text'] ); ?></td>
                            <td><?php echo esc_html( str_replace('_', ' ', ucfirst( $question['question_type'] )) ); ?></td>
                            <td><?php echo $question['is_required'] ? 'Yes' : 'No'; ?></td>
                            <td>
                                <?php if ( $question['question_type'] === 'dropdown' ) :
                                    $options = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$dropdown_options_table} WHERE question_id = %d ORDER BY sort_order ASC", $question['id'] ), ARRAY_A );
                                    $option_list = array_column($options, 'option_value');
                                    echo esc_html( implode(', ', $option_list) );
                                else :
                                    echo 'N/A';
                                endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ats-manage-questions&ats_action=edit_question&question_id=' . $question['id'] ) ); ?>" class="button button-secondary">Edit</a>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ats_manage_questions&ats_action=delete_question&question_id=' . $question['id'] ), 'ats_delete_question_nonce' ) ); ?>" class="button button-secondary" onclick="return confirm('Are you sure you want to delete this question? This will also delete all associated survey answers for this question.');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="ats-no-questions-message">No questions defined yet. Use the form below to add your first question.</p>
        <?php endif; ?>

        <h2 class="ats-admin-subtitle"><?php echo $editing_question ? 'Edit Question' : 'Add New Question'; ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="ats-admin-form">
            <?php wp_nonce_field( 'ats_add_edit_question_nonce', 'ats_admin_nonce' ); ?>
            <input type="hidden" name="action" value="ats_manage_questions">
            <input type="hidden" name="ats_action" value="<?php echo $editing_question ? 'update_question' : 'add_question'; ?>">
            <?php if ( $editing_question ) : ?>
                <input type="hidden" name="question_id" value="<?php echo esc_attr( $editing_question['id'] ); ?>">
            <?php endif; ?>

            <div class="ats-form-group">
                <label for="ats_question_text" class="ats-label">Question Text:</label>
                <input type="text" id="ats_question_text" name="ats_question_text" class="ats-input" value="<?php echo esc_attr( $editing_question ? $editing_question['question_text'] : '' ); ?>" required>
            </div>

            <div class="ats-form-group">
                <label for="ats_question_type" class="ats-label">Question Type:</label>
                <select id="ats_question_type" name="ats_question_type" class="ats-input" required onchange="toggleDropdownOptions(this)">
                    <option value="short_text" <?php selected( $editing_question['question_type'] ?? '', 'short_text' ); ?>>Short Text</option>
                    <option value="long_text" <?php selected( $editing_question['question_type'] ?? '', 'long_text' ); ?>>Long Text</option>
                    <option value="rating" <?php selected( $editing_question['question_type'] ?? '', 'rating' ); ?>>Rating (1-5)</option>
                    <option value="dropdown" <?php selected( $editing_question['question_type'] ?? '', 'dropdown' ); ?>>Dropdown</option>
                </select>
            </div>

            <div class="ats-form-group" id="ats_dropdown_options_group" style="display: none;">
                <label for="ats_dropdown_options" class="ats-label">Dropdown Options (comma-separated):</label>
                <textarea id="ats_dropdown_options" name="ats_dropdown_options" rows="3" class="ats-input" placeholder="e.g., Option 1, Option 2, Another Option"><?php echo esc_textarea( $editing_question['options_str'] ?? '' ); ?></textarea>
            </div>

            <div class="ats-form-group">
                <label for="ats_is_required" class="ats-label">Is Required?</label>
                <input type="checkbox" id="ats_is_required" name="ats_is_required" value="1" <?php checked( $editing_question ? $editing_question['is_required'] : 1 ); ?>>
            </div>

            <div class="ats-form-group">
                <label for="ats_sort_order" class="ats-label">Sort Order:</label>
                <input type="number" id="ats_sort_order" name="ats_sort_order" class="ats-input ats-sort-order-input" value="<?php echo esc_attr( $editing_question ? $editing_question['sort_order'] : count($questions) ); ?>" min="0">
            </div>

            <button type="submit" class="button button-primary button-large ats-submit-button-admin"><?php echo $editing_question ? 'Update Question' : 'Add Question'; ?></button>
            <?php if ( $editing_question ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ats-manage-questions' ) ); ?>" class="button button-secondary ats-cancel-button-admin">Cancel Edit</a>
            <?php endif; ?>
        </form>

        <script>
            function toggleDropdownOptions(selectElement) {
                const dropdownOptionsGroup = document.getElementById('ats_dropdown_options_group');
                if (selectElement.value === 'dropdown') {
                    dropdownOptionsGroup.style.display = 'block';
                } else {
                    dropdownOptionsGroup.style.display = 'none';
                }
            }
            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
                const questionTypeSelect = document.getElementById('ats_question_type');
                toggleDropdownOptions(questionTypeSelect);
            });
        </script>
    </div>
    <?php
}

/**
 * Helper function to summarize long question text for a cleaner results table.
 *
 * @param array $question The question array containing id and text.
 * @return string The summarized question text.
 */
function ats_get_summarized_question_text( $question ) {
    // Get survey options for dynamic question summaries
    $survey_options = get_option( 'ats_survey_options' );
    $ticket_question_id = isset( $survey_options['ticket_question_id'] ) ? (int) $survey_options['ticket_question_id'] : 0;
    $technician_question_id = isset( $survey_options['technician_question_id'] ) ? (int) $survey_options['technician_question_id'] : 0;

    // Prioritize checking by ID from settings
    if ( $ticket_question_id > 0 && $question['id'] == $ticket_question_id ) {
        return 'Ticket #';
    }
    if ( $technician_question_id > 0 && $question['id'] == $technician_question_id ) {
        return 'Technician';
    }

    // Fallback to text-based matching for backward compatibility and other default questions
    switch ( $question['question_text'] ) {
        case 'What is your ticket number?':
            return 'Ticket #';
        case 'Who was your technician for this ticket?':
            return 'Technician';
        case 'Overall, how would you rate the handling of your issue by the IT department?':
            return 'Overall Rating';
        case 'Were you helped in a timely manner?':
            return 'Timeliness';
        case 'Was your technician helpful?':
            return 'Helpfulness';
        case 'Was your technician courteous?':
            return 'Courtesy';
        case 'Did your technician demonstrate a reasonable understanding of your issue?':
            return 'Understanding';
        case 'Do you feel we could make an improvement, or have concerns about how your ticket was handled?':
            return 'Comments';
        default:
            // Fallback for new questions, summarize to the first few words
            $words = explode(' ', $question['question_text']);
            return implode(' ', array_slice($words, 0, 3)) . '...';
    }
}


/**
 * Callback for the View Results admin page, now using a dynamic CSS grid.
 */
function ats_display_view_results_page() {
    global $wpdb;
    global $ats_questions_table_name;
    global $ats_survey_submissions_table_name;
    global $ats_survey_answers_table_name;

    $questions_table   = $wpdb->prefix . $ats_questions_table_name;
    $submissions_table = $wpdb->prefix . $ats_survey_submissions_table_name;
    $answers_table     = $wpdb->prefix . $ats_survey_answers_table_name;

    // Get survey options
    $survey_options = get_option( 'ats_survey_options' );
    $ticket_question_id = isset( $survey_options['ticket_question_id'] ) ? (int) $survey_options['ticket_question_id'] : 0;
    $ticket_url_base = isset( $survey_options['ticket_url'] ) ? $survey_options['ticket_url'] : admin_url( 'admin.php?page=wpsc-tickets&thread_id=' );

    // Fetch all questions to use as headers and to map answers
    $questions = $wpdb->get_results( "SELECT id, question_text, question_type FROM {$questions_table} ORDER BY sort_order ASC", ARRAY_A );

    // Fetch all submissions, now including the user ID
    $submissions = $wpdb->get_results( "SELECT id, user_id, submission_date FROM {$submissions_table} ORDER BY submission_date DESC", ARRAY_A );

    // Adjust total columns for the new "Submitted by" column
    $total_columns = count($questions) + 3; // ID, Date, Submitted by, and all questions
    $grid_template_parts = array();
    for ($i = 0; $i < $total_columns - 1; $i++) {
        $grid_template_parts[] = 'auto';
    }
    $grid_template_parts[] = '1fr'; // The last column takes the remaining space
    $grid_template_columns = implode(' ', $grid_template_parts);

    ?>
    <style>
        /* Define the grid styles dynamically */
        .ats-results-grid {
            display: grid;
            grid-template-columns: <?php echo esc_attr($grid_template_columns); ?>;
            gap: 0;
            border: 1px solid #c3c4c7; /* A subtle border for the whole table */
        }
        .ats-results-header,
        .ats-results-row {
            display: contents; /* This is the key to making the children behave like table cells */
        }
        .ats-results-cell {
            padding: 12px 10px;
            border-bottom: 1px solid #e7e7e7;
            border-right: 1px solid #e7e7e7;
            word-wrap: break-word; /* Ensure long words wrap */
            overflow-wrap: break-word; /* Modern equivalent */
        }
        .ats-results-header .ats-results-cell {
            font-weight: bold;
            background-color: #f6f7f7;
            border-bottom: 2px solid #e7e7e7;
        }
        .ats-results-row:nth-child(even) .ats-results-cell {
            background-color: #f9f9f9;
        }
        /* Style for the last cell in a row to remove the right border */
        .ats-results-cell:last-child {
            border-right: none;
        }
        /* Style for the last row to remove the bottom border */
        .ats-results-row:last-child .ats-results-cell {
            border-bottom: none;
        }
        /* New style to center text in rating columns */
        .ats-rating-cell {
            text-align: center;
        }
    </style>
    <div class="wrap">
        <h1 class="ats-admin-main-title">View Survey Results</h1>

        <?php if ( $submissions ) : ?>
            <div class="widefat fixed striped ats-results-grid">
                <!-- Table Header -->
                <div class="ats-results-header">
                    <div class="ats-results-cell"><strong>ID</strong></div>
                    <div class="ats-results-cell"><strong>Date</strong></div>
                    <div class="ats-results-cell"><strong>Submitted by</strong></div>
                    <?php foreach ( $questions as $question ) : ?>
                        <?php
                            $header_class = '';
                            if ($question['question_type'] === 'rating') {
                                $header_class = 'ats-rating-cell';
                            }
                        ?>
                        <div class="ats-results-cell <?php echo esc_attr($header_class); ?>">
                            <strong><?php echo esc_html( ats_get_summarized_question_text( $question ) ); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Table Body -->
                <?php foreach ( $submissions as $submission ) : ?>
                    <div class="ats-results-row">
                        <div class="ats-results-cell"><?php echo esc_html( $submission['id'] ); ?></div>
                        <div class="ats-results-cell"><?php echo esc_html( $submission['submission_date'] ); ?></div>
                        <div class="ats-results-cell">
                            <?php
                                $user_info_text = 'N/A';
                                if ( ! empty( $submission['user_id'] ) ) {
                                    $user_data = get_userdata( $submission['user_id'] );
                                    if ( $user_data ) {
                                        $user_info_text = sprintf(
                                            'Username: %s, Display Name: %s',
                                            esc_html( $user_data->user_login ),
                                            esc_html( $user_data->display_name )
                                        );
                                    }
                                }
                                echo $user_info_text;
                            ?>
                        </div>
                        <?php
                        // Fetch answers for this specific submission
                        $answers = $wpdb->get_results( $wpdb->prepare(
                            "SELECT question_id, answer_value FROM {$answers_table} WHERE submission_id = %d",
                            $submission['id']
                        ), OBJECT_K );
                        ?>
                        <?php foreach ( $questions as $question ) : ?>
                            <?php
                                $cell_class = '';
                                if ($question['question_type'] === 'rating') {
                                    $cell_class = 'ats-rating-cell';
                                }
                            ?>
                            <div class="ats-results-cell <?php echo esc_attr($cell_class); ?>">
                                <?php
                                    $answer_obj = isset( $answers[ $question['id'] ] ) ? $answers[ $question['id'] ] : null;
                                    $answer_value = $answer_obj ? $answer_obj->answer_value : '';

                                    // Check if the current question is the ticket question and the answer is a valid number
                                    if ( $question['id'] == $ticket_question_id && ! empty( $answer_value ) && is_numeric( $answer_value ) ) {
                                        // Create a link to the ticketing system
                                        $ticket_url = $ticket_url_base . intval( $answer_value );
                                        echo '<a href="' . esc_url( $ticket_url ) . '" target="_blank">' . esc_html( $answer_value ) . '</a>';
                                    } else {
                                        echo esc_html( $answer_value );
                                    }
                                ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p>No survey submissions yet.</p>
        <?php endif; ?>
    </div>
    <?php
}


/**
 * Re-indexes the sort order of all questions to be sequential (0, 1, 2...).
 */
function ats_reindex_questions_sort_order() {
    global $wpdb;
    global $ats_questions_table_name;
    $questions_table = $wpdb->prefix . $ats_questions_table_name;

    // Get all questions ordered by their current sort_order
    $questions = $wpdb->get_results( "SELECT id FROM {$questions_table} ORDER BY sort_order ASC" );

    $new_sort_order = 0;
    foreach ( $questions as $question ) {
        $wpdb->update(
            $questions_table,
            array( 'sort_order' => $new_sort_order ),
            array( 'id' => $question->id ),
            array( '%d' ),
            array( '%d' )
        );
        $new_sort_order++;
    }
}


/**
 * Handle admin form submissions for managing questions (add, update, delete).
 */
function ats_handle_admin_actions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'after-ticket-survey' ) );
    }

    // Nonce verification for add/update actions
    if ( isset( $_POST['ats_admin_nonce'] ) && ! wp_verify_nonce( $_POST['ats_admin_nonce'], 'ats_add_edit_question_nonce' ) ) {
        wp_die( __( 'Nonce verification failed.', 'after-ticket-survey' ) );
    }

    // Nonce verification for delete action (uses a different nonce)
    if ( isset( $_GET['ats_action'] ) && $_GET['ats_action'] === 'delete_question' && ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ats_delete_question_nonce' ) ) ) {
        wp_die( __( 'Nonce verification failed.', 'after-ticket-survey' ) );
    }


    global $wpdb;
    global $ats_questions_table_name;
    global $ats_dropdown_options_table_name;
    global $ats_survey_answers_table_name; // Need this for deleting answers

    $questions_table        = $wpdb->prefix . $ats_questions_table_name;
    $dropdown_options_table = $wpdb->prefix . $ats_dropdown_options_table_name;
    $answers_table          = $wpdb->prefix . $ats_survey_answers_table_name;

    $ats_action = isset( $_POST['ats_action'] ) ? sanitize_text_field( $_POST['ats_action'] ) : (isset($_GET['ats_action']) ? sanitize_text_field($_GET['ats_action']) : '');

    if ( $ats_action === 'add_question' || $ats_action === 'update_question' ) {
        $question_text = sanitize_text_field( $_POST['ats_question_text'] );
        $question_type = sanitize_text_field( $_POST['ats_question_type'] );
        $is_required   = isset( $_POST['ats_is_required'] ) ? 1 : 0;
        $submitted_sort_order = isset( $_POST['ats_sort_order'] ) ? intval( $_POST['ats_sort_order'] ) : 0;
        $dropdown_options_str = sanitize_textarea_field( $_POST['ats_dropdown_options'] );

        $question_id = 0; // Initialize question_id

        if ( $ats_action === 'add_question' ) {
            // Shift existing questions to make space for the new one at the desired sort_order
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$questions_table} SET sort_order = sort_order + 1 WHERE sort_order >= %d",
                $submitted_sort_order
            ) );

            $wpdb->insert(
                $questions_table,
                array(
                    'question_text' => $question_text,
                    'question_type' => $question_type,
                    'sort_order'    => $submitted_sort_order, // Insert at the desired position
                    'is_required'   => $is_required,
                ),
                array( '%s', '%s', '%d', '%d' )
            );
            $question_id = $wpdb->insert_id;
            $message = 'added';
        } elseif ( $ats_action === 'update_question' ) {
            $question_id = isset( $_POST['question_id'] ) ? intval( $_POST['question_id'] ) : 0;
            if ( $question_id > 0 ) {
                // Get current sort order of the question being updated
                $old_sort_order = $wpdb->get_var( $wpdb->prepare(
                    "SELECT sort_order FROM {$questions_table} WHERE id = %d",
                    $question_id
                ) );
                $old_sort_order = intval($old_sort_order); // Ensure it's an integer

                if ( $submitted_sort_order < $old_sort_order ) {
                    // Moving item up: Shift items between new_pos and old_pos-1 down by 1
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$questions_table} SET sort_order = sort_order + 1 WHERE sort_order >= %d AND sort_order < %d AND id != %d",
                        $submitted_sort_order,
                        $old_sort_order,
                        $question_id
                    ) );
                } elseif ( $submitted_sort_order > $old_sort_order ) {
                    // Moving item down: Shift items between old_pos+1 and new_pos up by 1
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$questions_table} SET sort_order = sort_order - 1 WHERE sort_order <= %d AND sort_order > %d AND id != %d",
                        $submitted_sort_order,
                        $old_sort_order,
                        $question_id
                    ) );
                }

                $wpdb->update(
                    $questions_table,
                    array(
                        'question_text' => $question_text,
                        'question_type' => $question_type,
                        'sort_order'    => $submitted_sort_order, // Update to the new desired position
                        'is_required'   => $is_required,
                    ),
                    array( 'id' => $question_id ),
                    array( '%s', '%s', '%d', '%d' ),
                    array( '%d' )
                );
                $message = 'updated';
            } else {
                wp_redirect( admin_url( 'admin.php?page=ats-manage-questions&message=error' ) );
                exit;
            }
        }

        // Handle dropdown options for both add and update
        if ( $question_id && $question_type === 'dropdown' ) {
            // Delete existing options for this question before inserting new ones
            $wpdb->delete( $dropdown_options_table, array( 'question_id' => $question_id ), array( '%d' ) );

            $options_array = array_map( 'trim', explode( ',', $dropdown_options_str ) );
            $option_sort_order = 0;
            foreach ( $options_array as $option_value ) {
                if ( ! empty( $option_value ) ) {
                    $wpdb->insert(
                        $dropdown_options_table,
                        array(
                            'question_id'  => $question_id,
                            'option_value' => $option_value,
                            'sort_order'   => $option_sort_order++,
                        ),
                        array( '%d', '%s', '%d' )
                    );
                }
            }
        } elseif ( $question_id && $question_type !== 'dropdown' ) {
            // If question type changed from dropdown, clear its options
            $wpdb->delete( $dropdown_options_table, array( 'question_id' => $question_id ), array( '%d' ) );
        }

        // Always re-index all questions after add/update/delete to ensure consistent sequential order
        // This acts as a cleanup and normalization step after explicit shifts.
        ats_reindex_questions_sort_order();

        wp_redirect( admin_url( 'admin.php?page=ats-manage-questions&message=' . $message ) );
        exit;

    } elseif ( $ats_action === 'delete_question' ) {
        $question_id = isset( $_GET['question_id'] ) ? intval( $_GET['question_id'] ) : 0;

        if ( $question_id > 0 ) {
            // Delete associated dropdown options
            $wpdb->delete( $dropdown_options_table, array( 'question_id' => $question_id ), array( '%d' ) );

            // Delete associated answers from survey_answers table
            $wpdb->delete( $answers_table, array( 'question_id' => $question_id ), array( '%d' ) );

            // Delete the question itself
            $wpdb->delete( $questions_table, array( 'id' => $question_id ), array( '%d' ) );

            // Re-index all questions after deletion
            ats_reindex_questions_sort_order();

            wp_redirect( admin_url( 'admin.php?page=ats-manage-questions&message=deleted' ) );
        exit;
        } else {
            wp_redirect( admin_url( 'admin.php?page=ats-manage-questions&message=error' ) );
            exit;
        }
    }
}
add_action( 'admin_post_ats_manage_questions', 'ats_handle_admin_actions' );

/**
 * Callback function for the Manage Submissions admin page.
 */
function ats_display_manage_submissions_page() {
    global $wpdb;
    global $ats_survey_submissions_table_name;
    $submissions_table = $wpdb->prefix . $ats_survey_submissions_table_name;

    // Fetch all submissions
    $submissions = $wpdb->get_results( "SELECT id, submission_date FROM {$submissions_table} ORDER BY submission_date DESC", ARRAY_A );
    ?>
    <div class="wrap">
        <h1 class="ats-admin-main-title">Manage Survey Submissions</h1>
        <p class="ats-admin-intro-text">Select one or more submissions below and click "Delete" to permanently remove them from the database.</p>

        <?php if ( $submissions ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="ats-admin-form">
                <?php wp_nonce_field( 'ats_delete_submissions_nonce', 'ats_admin_nonce' ); ?>
                <input type="hidden" name="action" value="ats_manage_submissions">
                <input type="hidden" name="ats_action" value="delete_selected">
                <table class="wp-list-table widefat fixed striped ats-admin-table">
                    <thead>
                        <tr>
                            <th class="manage-column check-column"><input type="checkbox" id="ats_select_all"></th>
                            <th class="manage-column">Submission ID</th>
                            <th class="manage-column">Date Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $submissions as $submission ) : ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="selected_submissions[]" value="<?php echo esc_attr( $submission['id'] ); ?>">
                                </th>
                                <td><?php echo esc_html( $submission['id'] ); ?></td>
                                <td><?php echo esc_html( $submission['submission_date'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <br>
                <button type="submit" class="button button-primary button-large ats-submit-button-admin" onclick="return confirm('Are you sure you want to delete the selected submissions? This action cannot be undone.');">
                    Delete Selected Submissions
                </button>
            </form>
            <script>
                // JavaScript for select all checkbox
                document.addEventListener('DOMContentLoaded', function() {
                    const selectAllCheckbox = document.getElementById('ats_select_all');
                    const checkboxes = document.querySelectorAll('input[name="selected_submissions[]"]');

                    if (selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            checkboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                });
            </script>
        <?php else : ?>
            <p>No survey submissions to manage yet.</p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Handle admin form submissions for deleting survey submissions.
 */
function ats_handle_manage_submissions() {
    // Check user capability
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'after-ticket-survey' ) );
    }

    // Check nonce
    if ( ! isset( $_POST['ats_admin_nonce'] ) || ! wp_verify_nonce( $_POST['ats_admin_nonce'], 'ats_delete_submissions_nonce' ) ) {
        wp_die( __( 'Nonce verification failed.', 'after-ticket-survey' ) );
    }

    global $wpdb;
    global $ats_survey_submissions_table_name;
    global $ats_survey_answers_table_name;
    $submissions_table = $wpdb->prefix . $ats_survey_submissions_table_name;
    $answers_table     = $wpdb->prefix . $ats_survey_answers_table_name;

    $ats_action = isset( $_POST['ats_action'] ) ? sanitize_text_field( $_POST['ats_action'] ) : '';

    if ( $ats_action === 'delete_selected' && isset( $_POST['selected_submissions'] ) && is_array( $_POST['selected_submissions'] ) ) {
        $submission_ids = array_map( 'intval', $_POST['selected_submissions'] );

        if ( ! empty( $submission_ids ) ) {
            // Delete answers first
            $answers_format_placeholders = implode( ',', array_fill( 0, count( $submission_ids ), '%d' ) );
            $answers_sql = "DELETE FROM {$answers_table} WHERE submission_id IN ($answers_format_placeholders)";
            $wpdb->query( $wpdb->prepare( $answers_sql, $submission_ids ) );

            // Delete submissions
            $submissions_format_placeholders = implode( ',', array_fill( 0, count( $submission_ids ), '%d' ) );
            $submissions_sql = "DELETE FROM {$submissions_table} WHERE id IN ($submissions_format_placeholders)";
            $wpdb->query( $wpdb->prepare( $submissions_sql, $submission_ids ) );

            wp_redirect( admin_url( 'admin.php?page=ats-manage-submissions&message=submissions_deleted' ) );
            exit;
        }
    }

    wp_redirect( admin_url( 'admin.php?page=ats-manage-submissions&message=error' ) );
    exit;
}
add_action( 'admin_post_ats_manage_submissions', 'ats_handle_manage_submissions' );

/**
 * Register settings, sections, and fields for the settings page.
 */
function ats_register_settings() {
    // Register the main setting group
    register_setting( 'ats-survey-settings-group', 'ats_survey_options' );

    // Add a section for general settings
    add_settings_section(
        'ats_general_settings_section',
        'General Settings',
        '__return_false', // No callback needed for the section description
        'ats-survey-settings'
    );

    // Add fields to the general settings section
    add_settings_field(
        'ats_background_color',
        'Survey Page Background Color',
        'ats_setting_background_color_callback',
        'ats-survey-settings',
        'ats_general_settings_section'
    );

    add_settings_field(
        'ats_ticket_question_id',
        'Ticket Number Question',
        'ats_setting_ticket_question_callback',
        'ats-survey-settings',
        'ats_general_settings_section'
    );

    add_settings_field(
        'ats_technician_question_id',
        'Technician Question',
        'ats_setting_technician_question_callback',
        'ats-survey-settings',
        'ats_general_settings_section'
    );

    add_settings_field(
        'ats_ticket_url',
        'Ticket System Base URL',
        'ats_setting_ticket_url_callback',
        'ats-survey-settings',
        'ats_general_settings_section'
    );
}
add_action( 'admin_init', 'ats_register_settings' );

/**
 * Callback function to render the background color setting field.
 */
function ats_setting_background_color_callback() {
    $options = get_option( 'ats_survey_options' );
    $color = isset( $options['background_color'] ) ? $options['background_color'] : '#c0d7e5';
    echo '<input type="text" name="ats_survey_options[background_color]" value="' . esc_attr( $color ) . '" class="ats-color-picker" />';
    echo '<p class="description">Select a background color for the survey page.</p>';
}

/**
 * Callback function to render the ticket question setting field.
 */
function ats_setting_ticket_question_callback() {
    global $wpdb;
    global $ats_questions_table_name;
    $questions_table = $wpdb->prefix . $ats_questions_table_name;
    $questions = $wpdb->get_results( "SELECT id, question_text FROM {$questions_table} ORDER BY sort_order ASC", ARRAY_A );
    $options = get_option( 'ats_survey_options' );
    $selected_question = isset( $options['ticket_question_id'] ) ? $options['ticket_question_id'] : '';

    echo '<select name="ats_survey_options[ticket_question_id]">';
    echo '<option value="">-- Select a Question --</option>';
    foreach ( $questions as $question ) {
        echo '<option value="' . esc_attr( $question['id'] ) . '" ' . selected( $selected_question, $question['id'], false ) . '>' . esc_html( $question['question_text'] ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Select the question that asks for the ticket number.</p>';
}

/**
 * Callback function to render the technician question setting field.
 */
function ats_setting_technician_question_callback() {
    global $wpdb;
    global $ats_questions_table_name;
    $questions_table = $wpdb->prefix . $ats_questions_table_name;
    $questions = $wpdb->get_results( "SELECT id, question_text FROM {$questions_table} WHERE question_type = 'dropdown' ORDER BY sort_order ASC", ARRAY_A );
    $options = get_option( 'ats_survey_options' );
    $selected_question = isset( $options['technician_question_id'] ) ? $options['technician_question_id'] : '';

    echo '<select name="ats_survey_options[technician_question_id]">';
    echo '<option value="">-- Select a Question --</option>';
    foreach ( $questions as $question ) {
        echo '<option value="' . esc_attr( $question['id'] ) . '" ' . selected( $selected_question, $question['id'], false ) . '>' . esc_html( $question['question_text'] ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Select the question that asks for the technician.</p>';
}

/**
 * Callback function to render the ticket URL setting field.
 */
function ats_setting_ticket_url_callback() {
    $options = get_option( 'ats_survey_options' );
    $url = isset( $options['ticket_url'] ) ? $options['ticket_url'] : admin_url( 'admin.php?page=wpsc-tickets&thread_id=' );
    echo '<input type="text" name="ats_survey_options[ticket_url]" value="' . esc_attr( $url ) . '" class="regular-text" />';
    echo '<p class="description">Enter the base URL for linking to a ticket. The ticket ID will be appended to this URL.</p>';
}

/**
 * Display the settings page.
 */
function ats_display_settings_page() {
    ?>
    <div class="wrap">
        <h1>After Ticket Survey Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'ats-survey-settings-group' );
            do_settings_sections( 'ats-survey-settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
