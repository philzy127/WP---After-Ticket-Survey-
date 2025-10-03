<?php
/**
 * Plugin Name: SupportCandy Custom Multi-Question Survey
 * Plugin URI:  https://example.com/
 * Description: Extends SupportCandy's satisfaction survey to include multiple custom questions.
 * Version:     1.0.0
 * Author:      Your Name/Company
 * Author URI:  https://example.com/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sc-custom-survey
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SCCustomMultiQuestionSurvey
 *
 * Handles the creation, display, and submission of a custom multi-question survey
 * integrated with SupportCandy.
 */
class SCCustomMultiQuestionSurvey {

    /**
     * The database table name for survey responses.
     * @var string
     */
    private $table_name;

    /**
     * Defines the survey questions.
     * @var array
     */
    private $survey_questions;

    /**
     * Constructor.
     * Initializes hooks and sets up the database table name.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sc_custom_surveys';

        // Define your survey questions here
        $this->survey_questions = array(
            'overall_satisfaction' => array(
                'label'   => 'How satisfied were you with the overall support?',
                'type'    => 'radio',
                'options' => array(
                    'very_satisfied' => 'Very Satisfied',
                    'satisfied'      => 'Satisfied',
                    'neutral'        => 'Neutral',
                    'dissatisfied'   => 'Dissatisfied',
                    'very_dissatisfied' => 'Very Dissatisfied',
                ),
                'required' => true,
            ),
            'agent_knowledge' => array(
                'label'   => 'How knowledgeable was the support agent?',
                'type'    => 'radio',
                'options' => array(
                    'excellent' => 'Excellent',
                    'good'      => 'Good',
                    'average'   => 'Average',
                    'poor'      => 'Poor',
                ),
                'required' => true,
            ),
            'resolution_speed' => array(
                'label'   => 'How quickly was your issue resolved?',
                'type'    => 'radio',
                'options' => array(
                    'very_fast' => 'Very Fast',
                    'fast'      => 'Fast',
                    'average'   => 'Average',
                    'slow'      => 'Slow',
                    'very_slow' => 'Very Slow',
                ),
                'required' => true,
            ),
            'additional_comments' => array(
                'label'   => 'Do you have any additional comments or suggestions?',
                'type'    => 'textarea',
                'required' => false,
            ),
        );

        // Activation hook for database table creation
        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        // Add action to send survey email after ticket closure
        // Note: SupportCandy might have a specific hook for ticket closure.
        // If 'supportcandy_after_ticket_status_change' is too broad,
        // you might need to check for the 'closed' status within the callback.
        add_action( 'supportcandy_after_ticket_status_change', array( $this, 'send_custom_survey_email' ), 10, 3 );
        // Alternatively, if a direct 'ticket closed' hook exists:
        // add_action( 'supportcandy_ticket_closed', array( $this, 'send_custom_survey_email_direct' ), 10, 1 );


        // Add shortcode for displaying the survey form
        add_shortcode( 'sc_multi_question_survey', array( $this, 'render_survey_form_shortcode' ) );

        // Handle survey form submission
        add_action( 'template_redirect', array( $this, 'handle_survey_submission' ) );
    }

    /**
     * Plugin activation hook.
     * Creates the database table for survey responses.
     */
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) NOT NULL,
            customer_id bigint(20) NOT NULL,
            survey_token varchar(255) NOT NULL UNIQUE,
            overall_satisfaction varchar(50) DEFAULT NULL,
            agent_knowledge varchar(50) DEFAULT NULL,
            resolution_speed varchar(50) DEFAULT NULL,
            additional_comments text DEFAULT NULL,
            submission_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY customer_id (customer_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Sends the custom survey email when a ticket status changes to 'closed'.
     *
     * @param int    $ticket_id   The ID of the ticket.
     * @param string $old_status  The old status of the ticket.
     * @param string $new_status  The new status of the ticket.
     */
    public function send_custom_survey_email( $ticket_id, $old_status, $new_status ) {
        // Only send if the ticket is being closed
        if ( 'closed' !== $new_status ) {
            return;
        }

        // Get ticket object to retrieve customer email
        $ticket = wpsc_get_ticket( $ticket_id );
        if ( ! $ticket ) {
            error_log( 'SupportCandy Custom Survey: Could not retrieve ticket for ID ' . $ticket_id );
            return;
        }

        $customer_email = $ticket->customer->email;
        $customer_id    = $ticket->customer->id; // SupportCandy customer ID

        if ( ! $customer_email ) {
            error_log( 'SupportCandy Custom Survey: No customer email found for ticket ID ' . $ticket_id );
            return;
        }

        // Generate a unique token for the survey link
        $survey_token = wp_generate_uuid4();

        // Store the token, ticket ID, and customer ID in the database
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'ticket_id'   => $ticket_id,
                'customer_id' => $customer_id,
                'survey_token' => $survey_token,
            ),
            array( '%d', '%d', '%s' )
        );

        if ( $wpdb->last_error ) {
            error_log( 'SupportCandy Custom Survey: Database error inserting survey token: ' . $wpdb->last_error );
            return;
        }

        // Get the URL of the page where the shortcode is placed
        // IMPORTANT: Replace 'your-survey-page-slug' with the actual slug of your WordPress page.
        $survey_page_url = get_permalink( get_page_by_path( 'your-survey-page-slug' ) );

        if ( ! $survey_page_url ) {
            error_log( 'SupportCandy Custom Survey: Survey page not found. Please ensure the page with shortcode exists and slug is correct.' );
            return;
        }

        $survey_link = add_query_arg( array(
            'sc_survey_token' => $survey_token,
            'ticket_id'       => $ticket_id // Include ticket_id for convenience on the survey page
        ), $survey_page_url );

        $subject = sprintf( __( 'How was your experience with Ticket #%d?', 'sc-custom-survey' ), $ticket_id );
        $message = sprintf(
            __( "Hello %s,\n\nYour recent support ticket #%d has been closed. We'd love to hear about your experience to help us improve!\n\nPlease take a few moments to complete our quick survey:\n%s\n\nThank you for your feedback!", 'sc-custom-survey' ),
            $ticket->customer->first_name, // Assuming first_name is available, adjust if needed
            $ticket_id,
            $survey_link
        );

        // SupportCandy's email sending function (example, adjust based on actual SupportCandy API)
        // You might need to use SupportCandy's email notification system or wp_mail directly.
        // For simplicity, using wp_mail here.
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $sent = wp_mail( $customer_email, $subject, $message, $headers );

        if ( ! $sent ) {
            error_log( 'SupportCandy Custom Survey: Failed to send survey email for ticket ID ' . $ticket_id );
        }
    }

    /**
     * Renders the multi-question survey form using a shortcode.
     * [sc_multi_question_survey]
     *
     * @param array $atts Shortcode attributes (not used in this example).
     * @return string The HTML content of the survey form.
     */
    public function render_survey_form_shortcode( $atts ) {
        if ( isset( $_GET['sc_survey_token'] ) && isset( $_GET['ticket_id'] ) ) {
            $survey_token = sanitize_text_field( $_GET['sc_survey_token'] );
            $ticket_id    = absint( $_GET['ticket_id'] );

            global $wpdb;
            $existing_entry = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $this->table_name WHERE survey_token = %s AND ticket_id = %d",
                    $survey_token,
                    $ticket_id
                )
            );

            if ( ! $existing_entry ) {
                return '<p style="color: red;">' . __( 'Invalid survey link or ticket ID.', 'sc-custom-survey' ) . '</p>';
            }

            // Check if already submitted
            if ( ! empty( $existing_entry->overall_satisfaction ) ) {
                return '<p style="color: green;">' . __( 'Thank you! You have already completed this survey.', 'sc-custom-survey' ) . '</p>';
            }

            ob_start(); // Start output buffering

            ?>
            <div class="sc-custom-survey-container" style="max-width: 600px; margin: 20px auto; padding: 30px; border: 1px solid #eee; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); font-family: sans-serif; background-color: #fff;">
                <h2 style="text-align: center; color: #333; margin-bottom: 25px;">Support Experience Survey for Ticket #<?php echo esc_html( $ticket_id ); ?></h2>
                <p style="text-align: center; color: #666; margin-bottom: 30px;">Your feedback helps us improve our service. Please answer the questions below.</p>

                <form method="post" action="" class="sc-custom-survey-form">
                    <?php wp_nonce_field( 'sc_survey_submit', 'sc_survey_nonce' ); ?>
                    <input type="hidden" name="sc_survey_token" value="<?php echo esc_attr( $survey_token ); ?>">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket_id ); ?>">
                    <input type="hidden" name="action" value="sc_submit_survey">

                    <?php $question_number = 1; ?>
                    <?php foreach ( $this->survey_questions as $name => $question ) : ?>
                        <div class="sc-survey-question" style="margin-bottom: 25px;">
                            <label for="<?php echo esc_attr( $name ); ?>" style="display: block; font-weight: bold; margin-bottom: 10px; color: #444;">
                                <?php echo esc_html( $question_number++ . '. ' . $question['label'] ); ?>
                                <?php if ( $question['required'] ) : ?>
                                    <span style="color: red;">*</span>
                                <?php endif; ?>
                            </label>

                            <?php if ( $question['type'] === 'radio' ) : ?>
                                <div class="sc-radio-group">
                                    <?php foreach ( $question['options'] as $value => $label ) : ?>
                                        <div style="margin-bottom: 8px;">
                                            <input type="radio" id="<?php echo esc_attr( $name . '_' . $value ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo $question['required'] ? 'required' : ''; ?> style="margin-right: 8px;">
                                            <label for="<?php echo esc_attr( $name . '_' . $value ); ?>"><?php echo esc_html( $label ); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ( $question['type'] === 'textarea' ) : ?>
                                <textarea id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5" <?php echo $question['required'] ? 'required' : ''; ?> style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;"></textarea>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" style="display: block; width: 100%; padding: 12px 20px; background-color: #0073aa; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; transition: background-color 0.3s ease;">
                        Submit Feedback
                    </button>
                </form>
            </div>
            <?php

            return ob_get_clean(); // Return the buffered content
        } else {
            return '<p>' . __( 'Please use the survey link provided in your email.', 'sc-custom-survey' ) . '</p>';
        }
    }

    /**
     * Handles the submission of the survey form.
     */
    public function handle_survey_submission() {
        if ( isset( $_POST['action'] ) && $_POST['action'] === 'sc_submit_survey' && isset( $_POST['sc_survey_token'] ) && isset( $_POST['ticket_id'] ) ) {
            if ( ! wp_verify_nonce( $_POST['sc_survey_nonce'], 'sc_survey_submit' ) ) {
                wp_die( __( 'Security check failed.', 'sc-custom-survey' ) );
            }

            global $wpdb;
            $survey_token = sanitize_text_field( $_POST['sc_survey_token'] );
            $ticket_id    = absint( $_POST['ticket_id'] );

            // Fetch the existing entry to ensure token/ticket_id match and it hasn't been submitted
            $existing_entry = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $this->table_name WHERE survey_token = %s AND ticket_id = %d",
                    $survey_token,
                    $ticket_id
                )
            );

            if ( ! $existing_entry || ! empty( $existing_entry->overall_satisfaction ) ) {
                // Invalid token/ticket_id or already submitted
                wp_redirect( add_query_arg( 'survey_status', 'invalid_or_submitted', get_permalink( get_page_by_path( 'your-survey-page-slug' ) ) ) );
                exit;
            }

            $data_to_update = array(
                'submission_date' => current_time( 'mysql' ),
            );
            $formats = array( '%s' );

            foreach ( $this->survey_questions as $name => $question ) {
                if ( isset( $_POST[ $name ] ) ) {
                    if ( $question['type'] === 'textarea' ) {
                        $data_to_update[ $name ] = sanitize_textarea_field( $_POST[ $name ] );
                        $formats[] = '%s';
                    } else { // radio buttons
                        $data_to_update[ $name ] = sanitize_text_field( $_POST[ $name ] );
                        $formats[] = '%s';
                    }
                } elseif ( $question['required'] ) {
                    // Handle missing required field - redirect back with an error
                    wp_redirect( add_query_arg( 'survey_status', 'missing_required', get_permalink( get_page_by_path( 'your-survey-page-slug' ) ) ) );
                    exit;
                }
            }

            $updated = $wpdb->update(
                $this->table_name,
                $data_to_update,
                array( 'id' => $existing_entry->id ),
                $formats,
                array( '%d' )
            );

            if ( $updated === false ) {
                error_log( 'SupportCandy Custom Survey: Database update error: ' . $wpdb->last_error );
                wp_redirect( add_query_arg( 'survey_status', 'error', get_permalink( get_page_by_path( 'your-survey-page-slug' ) ) ) );
                exit;
            }

            // Redirect to a success message or the same page with a success status
            wp_redirect( add_query_arg( 'survey_status', 'success', get_permalink( get_page_by_path( 'your-survey-page-slug' ) ) ) );
            exit;
        }

        // Display status messages on the survey page after redirect
        if ( isset( $_GET['survey_status'] ) ) {
            add_action( 'wp_head', function() {
                $status = sanitize_text_field( $_GET['survey_status'] );
                if ( $status === 'success' ) {
                    echo '<div style="background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center;">' . esc_html__( 'Thank you for your feedback! Your survey has been submitted.', 'sc-custom-survey' ) . '</div>';
                } elseif ( $status === 'invalid_or_submitted' ) {
                    echo '<div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center;">' . esc_html__( 'This survey link is invalid or has already been submitted.', 'sc-custom-survey' ) . '</div>';
                } elseif ( $status === 'missing_required' ) {
                    echo '<div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center;">' . esc_html__( 'Please fill out all required fields.', 'sc-custom-survey' ) . '</div>';
                } elseif ( $status === 'error' ) {
                    echo '<div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center;">' . esc_html__( 'An error occurred during submission. Please try again.', 'sc-custom-survey' ) . '</div>';
                }
            });
        }
    }
}

// Instantiate the class to run the plugin
new SCCustomMultiQuestionSurvey();

/**
 * Helper function to get a SupportCandy ticket object.
 * This function assumes SupportCandy's API is available.
 * You might need to adjust this based on the exact SupportCandy version and its public API.
 * For example, older versions might use a different function or global object.
 *
 * @param int $ticket_id The ID of the SupportCandy ticket.
 * @return object|false The ticket object on success, false on failure.
 */
if ( ! function_exists( 'wpsc_get_ticket' ) ) {
    function wpsc_get_ticket( $ticket_id ) {
        // This is a placeholder. You need to use the actual SupportCandy function
        // to retrieve a ticket object. It's often something like:
        // return WPSC_Ticket_Manager::get_instance()->get_ticket( $ticket_id );
        // Or if it's a global function:
        // return supportcandy_get_ticket_by_id( $ticket_id );
        //
        // For demonstration, we'll return a mock object.
        // In a real scenario, ensure SupportCandy's classes/functions are loaded.

        // Mock object for demonstration purposes
        return (object) array(
            'id' => $ticket_id,
            'customer' => (object) array(
                'id' => 123, // Example customer ID
                'email' => 'customer@example.com',
                'first_name' => 'John',
            ),
            'status' => 'closed',
            // ... other ticket properties
        );
    }
}
