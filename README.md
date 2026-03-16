# Twig PDF Generator for LimeSurvey

This plugin for LimeSurvey allows you to generate professional, customized PDF reports and email them directly after a survey is completed. It uses the power of Twig templates to give you full control over the design and layout of your documents.

## Features

*   **Dynamic PDF Generation**: Create PDF reports from survey responses on the fly.
*   **Twig Templating**: Utilize the flexible and powerful Twig templating engine to design your PDFs with custom HTML and CSS.
*   **Visualizations**: Includes support for simple charts and visual elements (e.g., progress bars) to represent survey data.
*   **mPDF Integration**: Leverages the `mPDF` library to ensure high-quality, print-optimized PDF generation with support for custom fonts and advanced styling.
*   **Email Automation**: Automatically send the generated PDF as an email attachment to a specified address or the participant's email.
*   **Easy Configuration**: All settings, including the PDF and email templates, can be managed directly within the LimeSurvey plugin settings.

## Installation

1.  Download the latest release from the [releases page](https://github.com/SirPauls/TwigPdfGenerator/releases).
2.  Unzip the archive.
3.  Upload the `TwigPdfGenerator` directory to your LimeSurvey `plugins` directory.
4.  Activate the plugin from the LimeSurvey administration panel.
5.  Configure the plugin settings, including your custom Twig templates for the PDF and email.

## Usage

1.  Navigate to the "Simple plugin settings" for your survey.
2.  Enable automatic emailing if desired. The plugin will search for a question with the code "email" or use the email from the participant table.
3.  Customize the PDF metadata (Title, Author, Subject, etc.).
4.  Define the PDF filename. You can use Twig syntax for dynamic naming (e.g., `report-{{ response.id }}.pdf`).
5.  Set the email subject line, also with Twig support.
6.  Insert your custom HTML and Twig code into the "PDF Template" and "Mail Template" fields.

### Twig Context

The following data is available in your Twig templates:

*   `response`: An array containing the raw response data (e.g., `{{ response.Q01 }}`).
*   `responsevalue`: An array containing the response data with answer codes replaced by their corresponding labels (e.g., `{{ responsevalue.Q01 }}` might output "Stimme voll zu" instead of "A1").
*   `questions`: An array of all survey questions, each with detailed properties:
    *   `text`: The question text.
    *   `helpText`: The help text.
    *   `answers`: For multiple-choice questions, this array contains the labels of all selected options.

### Example Twig Code

```twig
<h1>Report for {{ response.firstname }} {{ response.lastname }}</h1>

<h2>Summary of your Answers:</h2>

{% for question in questions %}
    <div class="question-block">
        <div class="question">{{ question.text }}</div>
        {% if question.answers %}
            <ul class="answer-list">
                {% for answer in question.answers %}
                    <li>{{ answer }}</li>
                {% endfor %}
            </ul>
        {% else %}
            <div class="answer">{{ responsevalue[question.code]|default('No answer') }}</div>
        {% endif %}
    </div>
{% endfor %}
```

## Copyright & License

-   **Author**: SirPauls ([https://sirpauls.com](https://sirpauls.com))
-   **License**: GNU General Public License v3.0
-   **Original Author**: This plugin is a modernized and enhanced version of the original `TwigPdfGenerator` by Adam Zammit, which was based on the work of Sam Mousa.
