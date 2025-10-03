# SupportCandy Custom Multi-Question Survey

**Contributors:** [Your Name/Company]
**Tags:** supportcandy, survey, feedback, customer support
**Requires at least:** 5.0
**Tested up to:** 6.0
**Stable tag:** 1.0.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

An extension for the SupportCandy WordPress plugin that allows you to send a detailed, multi-question survey to customers after their support tickets are closed.

## Description

This plugin enhances the customer feedback process by replacing the standard single-question satisfaction survey in SupportCandy with a customizable, multi-question survey. When a support ticket is marked as 'closed', this plugin automatically sends an email to the customer with a unique link to the survey form.

The responses are stored in a custom database table, allowing for more granular analysis of customer satisfaction regarding agent knowledge, resolution speed, and overall experience.

## Features

- **Multi-Question Surveys:** Ask several questions to get detailed feedback, not just a single rating.
- **Customizable Questions:** Easily define your own questions (radio buttons, text areas) directly in the plugin file.
- **Automated Email Trigger:** Automatically sends a survey email when a ticket status changes to 'closed'.
- **Unique Survey Links:** Each survey link is tied to a specific ticket and customer, preventing duplicate or anonymous submissions.
- **Custom Database Table:** Stores all survey responses neatly in a dedicated `wp_sc_custom_surveys` table.
- **Simple Shortcode Integration:** Display the survey form on any WordPress page using a simple shortcode.
- **Styled Form:** Comes with clean, basic styling for the survey form that can be easily customized with your theme's CSS.

## Requirements

- **WordPress:** Version 5.0 or higher.
- **SupportCandy Plugin:** Must be installed and activated.
- **PHP:** Version 7.0 or higher.

---

## Installation and Setup

Follow these steps to get the custom survey up and running:

### 1. Install the Plugin

1.  Download the plugin files.
2.  Upload the `wp-after-ticket-survey` folder to the `/wp-content/plugins/` directory on your WordPress site.
3.  Activate the plugin through the 'Plugins' menu in WordPress. Upon activation, a new database table (`wp_sc_custom_surveys`) will be created.

### 2. Create the Survey Page

1.  In your WordPress dashboard, go to **Pages > Add New**.
2.  Give the page a title, for example, "Customer Survey".
3.  In the content editor, insert the following shortcode:
    ```
    [sc_multi_question_survey]
    ```
4.  Publish the page. Note the slug of this page (e.g., `customer-survey`).

### 3. Configure the Plugin File

You must edit the `sc-custom-survey.php` file to link it to your newly created survey page.

1.  Open the plugin file: `wp-content/plugins/wp-after-ticket-survey/sc-custom-survey.php`.
2.  Locate the `send_custom_survey_email` function (around line 170).
3.  Find this line:
    ```php
    $survey_page_url = get_permalink( get_page_by_path( 'your-survey-page-slug' ) );
    ```
4.  Replace `'your-survey-page-slug'` with the actual slug of the page you created in Step 2. For example:
    ```php
    $survey_page_url = get_permalink( get_page_by_path( 'customer-survey' ) );
    ```
5.  Save the file.

### 4. IMPORTANT: Update the Ticket Retrieval Function

This plugin uses a placeholder function `wpsc_get_ticket()` to fetch ticket details from SupportCandy. You **must** replace its content with the correct function provided by your version of SupportCandy.

1.  In the `sc-custom-survey.php` file, scroll to the bottom to find the `wpsc_get_ticket` function.
2.  **Delete the mock object code** inside it.
3.  Replace it with the actual API call from SupportCandy. It might be something like:
    ```php
    // Example - check SupportCandy documentation for the correct function
    if ( class_exists( 'WPSC_Ticket_Manager' ) ) {
        return WPSC_Ticket_Manager::get_instance()->get_ticket( $ticket_id );
    }
    return false;
    ```
    Or:
    ```php
    // Another possible example
    if ( function_exists( 'supportcandy_get_ticket' ) ) {
        return supportcandy_get_ticket( $ticket_id );
    }
    return false;
    ```
    **Failure to update this function will result in the plugin not being able to retrieve ticket information and send emails correctly.**

---

## How It Works

1.  **Ticket Closed:** An agent closes a support ticket in SupportCandy.
2.  **Email Sent:** The plugin hooks into this action, generates a unique survey token, and sends an email to the customer. The email contains a link to the survey page.
3.  **Customer Completes Survey:** The customer clicks the link, which takes them to the survey page. The form is displayed, pre-associated with their ticket.
4.  **Submission Stored:** Upon submission, the answers are saved to the `wp_sc_custom_surveys` database table, linked to the original `ticket_id` and `customer_id`.

## Customization

### Editing Survey Questions

You can easily add, remove, or modify survey questions by editing the `$survey_questions` array within the `SCCustomMultiQuestionSurvey` class constructor (around line 40 of `sc-custom-survey.php`).

The array structure is as follows:
```php
'question_key' => array(
    'label'   => 'Your Question Here?',
    'type'    => 'radio' or 'textarea',
    'options' => array( // Only for 'radio' type
        'value1' => 'Label 1',
        'value2' => 'Label 2',
    ),
    'required' => true or false,
),
```

### Editing Email Content

The subject and body of the survey email can be customized in the `send_custom_survey_email` function. Look for the `$subject` and `$message` variables to change the text.

## Database

The plugin creates one custom table:

-   **`{prefix}_sc_custom_surveys`**: Stores the survey responses.
    -   `id`: Unique ID for the submission.
    -   `ticket_id`: The ID of the associated SupportCandy ticket.
    -   `customer_id`: The ID of the submitting customer.
    -   `survey_token`: The unique token used to access the survey.
    -   `overall_satisfaction`, `agent_knowledge`, etc.: Columns for each question's answer.
    -   `submission_date`: Timestamp of the survey submission.

## Shortcode

-   **`[sc_multi_question_survey]`**
    -   This shortcode renders the survey form. It should be placed on a public-facing page. The form will only display when accessed via a valid survey link from the customer's email.

---

## Changelog

### 1.0.0
* Initial release.