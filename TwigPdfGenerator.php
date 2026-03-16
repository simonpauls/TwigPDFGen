<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * LimeSurvey plugin to generate an emailable PDF report using Twig
 * php version 8.2
 *
 * @category Plugin
 * @package  LimeSurvey
 * @author   Adam Zammit <adam.zammit@acspri.org.au>
 * @license  GPLv3 https://www.gnu.org/licenses/gpl-3.0.en.html
 */

/**
 * Generate an emailable PDF report based on Twig
 *
 * @category Plugin
 * @package  LimeSurvey
 * @author   Adam Zammit <adam.zammit@acspri.org.au>
 * @license  GPLv3 https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */


class TwigPdfGenerator extends PluginBase
{

    protected $storage = 'DbStorage';

    static protected $description = 'Generate a PDF and email from a Twig template';
    static protected $name = 'TwigPdfGenerator';

    private function getTempDir()
    {
        $dir = $this->api->getConfigKey('tempdir') . '/twigcache';

        if (!is_dir($dir)) {
            if (!mkdir($dir)) {
                return false;
            }
        }
        return $dir;

    }

    public function init()
    {
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('newDirectRequest');
        $this->loadResponseScript();
    }

    public function loadResponseScript()
    {
        if (!preg_match('-^(?:/index\.php)?/responses/browse\?surveyId=(\d+)-', $this->api->getRequest()->getRequestUri(), $matches)) {
            return ;
        }
        $url = $this->api->createUrl('plugins/direct', [
            'plugin' => self::$name,
            'function' => 'previewPdf',
            'surveyId' => $matches[1],
            'responseId' => ''
        ]);

        $js = <<<JS
    // Selector for LS 6
    $("ul[id^='dropdownmenu']").each(function() {
        if ($(this).find('a.twigpdfgenerator').length > 0) {
        return;
        }

       $('<li><div data-bs-toggle="tooltip" title="" class="twigpdfgenerator"><a class="dropdown-item twigpdfgenerator"><i class="ri-file-chart-line"></i>Download custom report</a></div></li>').prependTo(this)
    });

    $("div.twigpdfgenerator").each(function() {
	var id = $( this ).parent().parent().find('i.ri-download-fill.text-success').parent().attr('href').match(/\d+$/)[0];
        $( this ).find('a').attr('href', "$url" + id);
    });
JS;
        App()->clientScript->registerScript('twig1', $js);
        App()->clientScript->registerScript('twig2', "$(document).ajaxStop(function() { console.log('ajax stopped'); $js })");
    }

    public function newDirectRequest()
    {
        if ($this->event->get('target') !== $this->getName()) {
            return;
        }

        if ($this->api->getCurrentUser() === false) {
            \Yii::app()->user->returnUrl = $this->api->getRequest()->requestUri;
            \Yii::app()->user->loginRequired();
        }

    $surveyId = (int) $this->event->get('request')->getParam('surveyId');

        if (!Permission::model()->hasSurveyPermission($surveyId, 'response', 'read')) {
            throw new CHttpException(401);
        }

        $responseId = $this->event->get('request')->getParam('responseId');
        $download = true;
        if (!isset($responseId)) {
            $download = false;
            $responses = $this->api->getResponses($surveyId, [], [
                'condition' => 'submitdate is not null',
                'order' => 'id desc',
                'limit' => 1,
                'select' => 'id'
            ]);
            if (!empty($responses)) {
                $responseId = $responses[0]->id;
            }
        }

        if (!isset($responseId)) {
            http_response_code(404);
            die('Not found');
        }

        if ($this->event->get('function') == 'previewPdf') {
            return $this->previewPdf($surveyId, $responseId, $download);
        }

        if ($this->event->get('function') == 'previewMail') {
            return $this->previewMail($surveyId, $responseId);
        }




    }

    protected function previewPdf($surveyId, $responseId, $download = false)
    {
        $responseData = $this->api->getResponse($surveyId, $responseId);
        $responseValueData = $this->getResponseValue($surveyId, $responseId);
        $context = $this->createContext($surveyId, $responseData, $responseValueData);
        $pdfPlate = $this->get('pdfPlate', 'Survey', $surveyId);
        if ($download ) {
            $pdfFilename = $this->renderTwig($this->get('pdfFilename', 'Survey', $surveyId, 'report.pdf'), $context);
            header("Content-Disposition: attachment; filename=\"$pdfFilename\"");
        }

        header('Content-Type: application/pdf');
        header('Content-Transfer-Encoding: binary');
        echo $this->htmlToPdf($this->renderTwig($pdfPlate, $context), 'S', $surveyId);
        die();
    }

    protected function previewMail($surveyId, $responseId)
    {
        $responseData = $this->api->getResponse($surveyId, $responseId);
        $responseValueData = $this->getResponseValue($surveyId, $responseId);
        $context = $this->createContext($surveyId, $responseData, $responseValueData);
        $mailPlate = $this->get('mailPlate', 'Survey', $surveyId);
        $mailSubjectTemplate = $this->get('mailSubject', 'Survey', $surveyId);
        $subject = $this->renderTwig($mailSubjectTemplate, $context);
        $iframe = \CHtml::tag('iframe', [
            'srcdoc' => $this->renderTwig($mailPlate, $context),
            'style' => 'width: 100%; height: 100%; border: none;'
        ]);
        echo <<<HTML
<html>
<title>Mail preview</title>
<body style="padding: 0px; margin: 0px;">
<div style="position: fixed; height: 100px; padding: 5px;">
<h1>Subject: $subject</h1>
</div>
<div style="position: fixed; left: 5px; bottom: 5px; top: 100px; right: 5px; border: 1px solid gray;">
$iframe
</div>
</body>
</html>
HTML;

        die();
    }
    protected function createContext($surveyId, array $responseData, $responseValueData = false)
    {
        $context = [];
        // Iterate over all questions.
        $lang = isset($responseData['startlanguage']) ? $responseData['startlanguage'] : 'en';
        foreach($this->api->getQuestions($surveyId, $lang, [
            'parent_qid' => 0
        ]) as $question) {
            $row = [
                'group_id' => $question->gid,
                'code' => $question->title,
                'text' => $question->questionl10ns[$lang]->question,
                'helpText' => $question->questionl10ns[$lang]->help,
            ];
            $row['value'] = isset($responseValueData[$question->title]) ? $responseValueData[$question->title] : (isset($responseData[$question->title]) ? $responseData[$question->title] : null);
            
            // Question type code
            $row['type'] = $question->type;

            // Check for potential comment field (e.g., List with Comment 'Z' or Multiple Choice with comments)
            $commentKey = "{$question->title}comment";
            $row['comment'] = isset($responseData[$commentKey]) ? $responseData[$commentKey] : null;

            // Initialize answers array for complex types
            // Complex types include: Multiple Choice, Arrays, Ranking, File Upload, List with Comment
            if (in_array($question->type, ["M", "P", "K", "Q", "R", "A", "B", "C", "E", "F", "H", ":", ";", "Z", "|"])) {
                $row['answers'] = [];
            }

            // Specific handling for File Upload (|)
            if ($question->type == "|") {
                $fileContent = isset($responseData[$question->title]) ? $responseData[$question->title] : null;
                if (!empty($fileContent)) {
                    $fileData = json_decode($fileContent, true);
                    if (is_array($fileData)) {
                        $row['answers'] = $fileData;
                    }
                }
            }

            // Initialize Multiple Choice flags
            if (in_array($question->type, ["M", "P"])) {
                $row['falseNegatives'] = [];
                $row['trueNegatives'] = [];
                $row['falsePositives'] = [];
                $row['truePositives'] = [];
            }

            // Iterate over subquestions
            foreach($question->subquestions as $subQuestion) {
                $srow = [
                    'group_id' => $question->gid,
                    'code' => $subQuestion->title,
                    'text' => $subQuestion->questionl10ns[$lang]->question,
                    'helpText' => $subQuestion->questionl10ns[$lang]->help,
                ];
                     
                // Construct potential keys for data lookup
                $responseKey = "{$question->title}_{$subQuestion->title}";
                $rawKey = "{$surveyId}X{$question->gid}X{$question->qid}{$subQuestion->title}";
                $rawKey2 = "{$surveyId}X{$question->gid}X{$question->qid}_{$subQuestion->title}";

                // 1. Try mapped code from responseValueData
                $val = isset($responseValueData[$responseKey]) ? $responseValueData[$responseKey] : null;
                
                // 2. Try raw SIDXGIDXQID format from responseData
                if (empty($val)) {
                    if (isset($responseData[$rawKey])) {
                        $val = $responseData[$rawKey];
                    } elseif (isset($responseData[$rawKey2])) {
                        $val = $responseData[$rawKey2];
                    }
                }

                // 3. Fallback for ranking questions: map items to ranks
                if ($question->type == "R") {
                    // In ranking questions, the response data (both raw and mapped) 
                    // usually has keys like Q18_1, Q18_2... where the value is the item code.
                    // We need to find which rank (suffix) has this subquestion's code as its value.
                    
                    // Check mapped values first
                    foreach ($responseValueData as $rvKey => $rvVal) {
                        // Check if key starts with question title (e.g. Q18_1)
                        if (strpos($rvKey, $question->title) === 0) {
                            $cleanRvVal = trim(strip_tags(html_entity_decode($rvVal)));
                            $cleanSubQText = trim(strip_tags(html_entity_decode($subQuestion->questionl10ns[$lang]->question)));
                            
                            // Check if value matches subquestion title (code) or text
                            if ($rvVal == $subQuestion->title || $cleanRvVal == $cleanSubQText) {
                                // Extract rank from key (e.g. 1 from Q18_1)
                                if (preg_match('/_(\d+)$/', $rvKey, $matches)) {
                                    $rank = $matches[1];
                                    $row['answers'][(int)$rank] = $subQuestion->questionl10ns[$lang]->question;
                                }
                            }
                        }
                    }
                    
                    // Check raw values as well
                    foreach ($responseData as $rKey => $rVal) {
                        // Match Q18_1 or SIDXGIDXQID1
                        // Ensure we are looking at the right question by checking QID inclusion or Title prefix
                        if (strpos($rKey, $question->title . '_') === 0 || strpos($rKey, (string)$question->qid) !== false) {
                            if ($rVal == $subQuestion->title || $rVal == $subQuestion->qid . $subQuestion->title) {
                                $rank = null;
                                if (preg_match('/_(\d+)$/', $rKey, $matches)) {
                                    $rank = $matches[1];
                                } elseif (preg_match('/X' . $question->qid . '(\d+)$/', $rKey, $matches)) {
                                    // Match SIDXGIDXQID1 format where 1 is rank
                                    $rank = $matches[1];
                                }
                                
                                if ($rank !== null && is_numeric($rank)) {
                                    $row['answers'][(int)$rank] = $subQuestion->questionl10ns[$lang]->question;
                                }
                            }
                        }
                    }
                }

                // 4. Ultimate fallback: try searching responseData for any key containing SID, GID, QID and SQ title
                if (empty($val)) {
                    foreach ($responseData as $rKey => $rVal) {
                        if (strpos($rKey, (string)$question->qid) !== false && strpos($rKey, $subQuestion->title) !== false) {
                            $val = $rVal;
                            break;
                        }
                    }
                }

                $checked = !empty($val);
                $srow['value'] = $val;
                $srow['checked'] = $checked;

                // Check for subquestion comment (common in Multiple Choice with comments)
                $sqCommentKey = "{$question->title}_{$subQuestion->title}comment";
                $srow['comment'] = isset($responseData[$sqCommentKey]) ? $responseData[$sqCommentKey] : null;

                // Add subquestion data to global context
                $context['questions'][$question->title . "_" . $subQuestion->title] = $srow;

                // Populate the parent question's answers array for other types
                if ($question->type != "R") {
                    if ($checked) {
                        if (in_array($question->type, ["M", "P"])) {
                            // Multiple Choice: store label
                            $row['answers'][$subQuestion->title] = $subQuestion->questionl10ns[$lang]->question;
                        } elseif (in_array($question->type, ["K", "Q", "A", "B", "C", "E", "F", "H", ":", ";"])) {
                            // Multiple Input or Arrays: store value
                            $row['answers'][$subQuestion->title] = $val;
                        }
                    }
                }

                // Specific logic for Multiple Choice analysis
                if (in_array($question->type, ["M", "P"])) {
                    if (strncmp($subQuestion->title, 'C', 1) === 0) {
                        if ($checked) {
                            $row['truePositives'][$subQuestion->title] = $subQuestion->questionl10ns[$lang]->question;
                        } else {
                            $row['falseNegatives'][$subQuestion->title] = $subQuestion->questionl10ns[$lang]->question;
                        }
                    }
                    if (strncmp($subQuestion->title, 'I', 1) === 0) {
                        if ($checked) {
                            $row['falsePositives'][$subQuestion->title] = $subQuestion->questionl10ns[$lang]->question;
                        } else {
                            $row['trueNegatives'][$subQuestion->title] = $subQuestion->questionl10ns[$lang]->question;
                        }
                    }
                }
            }

            // Handle 'other' for multiple choice questions
            if (in_array($question->type, ["M", "P"])) {
                $otherKey = "{$question->title}_other";
                if (!empty($responseData[$otherKey])) {
                    $row['answers']['other'] = $responseData[$otherKey];
                }
            }

            // Sort ranking questions by their rank
            if ($question->type == "R" && !empty($row['answers'])) {
                ksort($row['answers']);
            }
            
            $context['questions'][$question->title] = $row;
        }

        $context['response'] = $responseData;
        $context['responsevalue'] = $responseValueData;
        if (!empty($responseData['token'])) {
            $context['token'] = $this->api->getToken($surveyId, $responseData['token'])->attributes;
        }

        $context['rankingDisplayType'] = $this->get('rankingDisplayType', 'Survey', $surveyId, 'numbered');
    
        return $context;
    }

    public function afterSurveyComplete()
    {
        $surveyId = $this->event->get('surveyId');
        if ($this->get('enabled', 'Survey', $surveyId, 0) == 0) {
            return;
        }

        $responseId = $this->event->get('responseId');

        try {
            $responseData = $this->api->getResponse($surveyId, $responseId);
            $responseValueData = $this->getResponseValue($surveyId, $responseId);
            $tokenEmail = !empty($responseData['token'])
                ? $this->api->getToken($surveyId, $responseData['token'])->email
                : null;


            $context = $this->createContext($surveyId, $responseData, $responseValueData);
            // Get templates.
            $mailPlate = $this->get('mailPlate', 'Survey', $surveyId);
            $pdfPlate = $this->get('pdfPlate', 'Survey', $surveyId);
            $mailSubjectTemplate = $this->get('mailSubject', 'Survey', $surveyId);

            $mail = $this->renderTwig($mailPlate, $context);
            $pdf = $this->htmlToPdf($this->renderTwig($pdfPlate, $context), 'S', $surveyId);
            $mailSubject = $this->renderTwig($mailSubjectTemplate, $context);

            $toMail = !empty($responseData['email']) ? $responseData['email'] : $tokenEmail;

            $pdfFilename = $this->renderTwig($this->get('pdfFilename', 'Survey', $surveyId, 'report.pdf'), $context);
            $attachment = [$pdf, $pdfFilename];

            $survey = \Survey::model()->findByPk($surveyId);
            $fromEmail = !empty($survey->adminemail)
                ? $survey->adminemail
                : \Yii::app()->getConfig('siteadminemail');

            $copyEmail = array_map('trim', explode(';', $this->get('mailCopy', 'Survey', $surveyId)));
            $this->sendMail($mail, $toMail, $attachment, $mailSubject, $fromEmail, $copyEmail);

        } catch (\CDbException $e) {
            // Do nothing; survey was not active.
        } catch (\Exception $e) {

        }
    }

    protected function sendMail($body, $to, $attachment, $subject, $from, array $copy)
    {
        if ($to !== null) {
            $mailer = new \LimeMailer();
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->setFrom($from);
            $mailer->Body = $body;
            $mailer->IsHTML(true);
            $mailer->addStringAttachment($attachment[0],$attachment[1]);
            $mailer->sendMessage();
        }

        if (!empty($copy) && is_array($copy)) {
            foreach ($copy as $cto) {
                $mailer = new \LimeMailer();
                $mailer->addAddress($cto);
                $mailer->Subject = $subject . " - [sent to: $to]";
                $mailer->setFrom($from);
                $mailer->Body = $body;
                $mailer->IsHTML(true);
                $mailer->addStringAttachment($attachment[0],$attachment[1]);
                $mailer->sendMessage();
            }
        }
    }

    private function getHelpText($field)
    {
        if (!isset($this->errors[$field])) {
            return '';
        }

        return \CHtml::tag('em', ['class' => 'error'], $this->errors[$field]['message']);
    }

    public function beforeSurveySettings()
    {
        /** @var \Survey $survey */
        $settings = [
            'name' => "TwigPdfGenerator",
            'settings' => [
                'enabled' => [
                    'type' => 'boolean',
                    'label' => 'Enable automatic sending (will send to any address stored in the survey with the question code "email", or the email in the particpants table)',
                    'current' => $this->get('enabled', 'Survey', $this->event->get('survey'), 0)
                ],
                'pdfAuthor' => [
                    'type' => 'string',
                    'label' => 'PDF Author',
                    'current' => $this->get('pdfAuthor', 'Survey', $this->event->get('survey'))
                ],
                'pdfTitle' => [
                    'type' => 'string',
                    'label' => 'PDF Title',
                    'help' => 'This is used in the header as well as the meta data',
                    'current' => $this->get('pdfTitle', 'Survey', $this->event->get('survey'))
                ],
                'pdfHeaderLogo' => [
                    'type' => 'string',
                    'label' => 'PDF Header logo',
                    'help' => 'Must be a URL or absolute path',
                    'current' => $this->get('pdfHeaderLogo', 'Survey', $this->event->get('survey'))
                ],
                'pdfHeaderWidth' => [
                    'type' => 'int',
                    'label' => 'PDF Header width',
                    'help' => 'In MM, page width for A4 is 210mm',
                    'current' => $this->get('pdfHeaderWidth', 'Survey', $this->event->get('survey'), 50)
                ],
                'pdfSubject' => [
                    'type' => 'string',
                    'label' => 'PDF Subject',
                    'current' => $this->get('pdfSubject', 'Survey', $this->event->get('survey'))
                ],
                'pdfFilename' => [
                    'type' => 'string',
                    'label' => 'PDF Filename',
                    'current' => $this->get('pdfFilename', 'Survey', $this->event->get('survey'), 'report.pdf'),
                    'help' => 'Name of the attachment, should end in ".pdf", can use twig template.'
                ],
                'mailSubject' => [
                    'type' => 'string',
                    'label' => 'Mail Subject',
                    'current' => $this->get('mailSubject', 'Survey', $this->event->get('survey'))
                ],
                'mailCopy' => [
                    'type' => 'string',
                    'label' => 'Copy email',
                    'help' => 'Send a copy of any outbound emails to this address, split multiple addressed with a ";"',
                    'current' => $this->get('mailCopy', 'Survey', $this->event->get('survey'))
                ],
                'rankingDisplayType' => [
                    'type' => 'select',
                    'label' => 'Ranking Question Display Format',
                    'options' => [
                        'numbered' => 'Numbered List',
                        'bullet' => 'Bulleted List'
                    ],
                    'help' => 'Choose how to display the ranked items in the PDF report.',
                    'current' => $this->get('rankingDisplayType', 'Survey', $this->event->get('survey'), 'numbered')
                ]
            ]
        ];



        $settings['settings']['pdfPlate'] = [
            'type' => 'text',
            'label' => "PDF Template",
            'help' => $this->getHelpText("pdfPlate"),
            'current' => isset($this->errors["pdfPlate"])
            ? $this->errors["pdfPlate"]['value']
            : $this->get("pdfPlate", 'Survey', $this->event->get('survey')),
        ];
        $settings['settings']['mailPlate'] = [
            'type' => 'text',
            'label' => "Mail Template",
            'help' => $this->getHelpText("mailPlate"),
            'current' => isset($this->errors["mailPlate"])
            ? $this->errors["mailPlate"]['value']
            : $this->get("mailPlate", 'Survey', $this->event->get('survey'))
        ];

        $settings['settings']['previewPdf'] = [
            'type' => 'info',
            'label' => 'Actions',
            'content' =>
            \CHtml::link("Preview PDF",
                $this->api->createUrl('plugins/direct', [
                    'plugin' => $this->getName(),
                    'function' => 'previewPdf',
                    'surveyId' => $this->event->get('survey'),
                ]),
                [
                    'target' => 'previewPdf',
                    'style' => "padding: 5px; border-radius: 5px;",
                    'class' => 'ui-state-default'
                ]
            ) . \CHtml::link("Preview Mail",
            $this->api->createUrl('plugins/direct', [
                'plugin' => $this->getName(),
                'function' => 'previewMail',
                'surveyId' => $this->event->get('survey'),
            ]),
            [
                'target' => 'previewMail',
                'style' => "padding: 5px; border-radius: 5px;",
                'class' => 'ui-state-default'
            ]
            )
        ];

        $this->event->set("surveysettings.{$this->id}", $settings);
    }


    private $errors = [];

    public function newSurveySettings()
    {
        $surveyId = $this->event->get('survey');
        $settings = $this->event->get('settings');

        // Validate
        foreach($settings as $key => $value) {
            if (strpos($key, 'Template') !== false) {
                try {
                    $this->renderTwig($value, $this->createContext($surveyId, [], false), $key);
                } catch (\Throwable $e) {
                    $this->errors[$key] = [
                        'message' => $e->getMessage(),
                        'value' => $settings[$key]
                    ];
                }

            }
        }
        if (!empty($this->errors)) {
            // Hack to prevent redirect on validation errors.
            unset($_POST['redirect']);
            return;

        }

        foreach ($settings as $name => $value) {
            $this->set($name, $value, 'Survey', $surveyId);
        }
    }


    /**
     * @param $html
     * @param $response
     * @return string The rendered template
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    protected function renderTwig($template, $context, $name = null)
    {
        if (!isset($template)) {
            return '';
        }
        if (isset($name)) {
            $key = $name;
        } else {
            $key = md5($template);
        }
        $loader = new \Twig\Loader\ArrayLoader([
            $key => $template
        ]);
        $twig = new \Twig\Environment($loader, [
            'cache' => $this->getTempDir(),
            'strict_variables' => false
        ]);

        return $twig->load($key)->render($context);
    }
    /**
     * @param string $html The HTML content
     * @return string The PDF content
     */
    protected function htmlToPdf($html, $destination, $surveyId)
    {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $this->getTempDir()
        ]);

        $mpdf->SetAuthor($this->get('pdfAuthor', 'Survey', $surveyId, 'TwigPdfGenerator'));
        $mpdf->SetTitle($this->get('pdfTitle', 'Survey', $surveyId, 'Ergebnisbericht'));
        $mpdf->SetSubject($this->get('pdfSubject', 'Survey', $surveyId, 'Persönlichkeits- und Kompetenztest 55+'));
        $mpdf->SetCreator('TwigPdfGenerator');

        // Add custom fonts if needed
        // $fontData = \Mpdf\Font\FontVariables::getFontVariables();
        // $defaultFontConfig = (new \Mpdf\Font\FontFile())->getFontConfig($fontData);
        // $fontData = $defaultFontConfig + [
        //     'roboto' => [
        //         'R' => 'Roboto-Regular.ttf',
        //         'B' => 'Roboto-Bold.ttf',
        //     ]
        // ];
        // $mpdf->fontdata = $fontData;
        // $mpdf->SetFont('roboto');

        $mpdf->WriteHTML($html);
        return $mpdf->Output('report.pdf', $destination);
    }

    /**
     * Gets a survey response from the database.
     *
     * @param int $surveyId
     * @param int $responseId
     * @param bool $bMapQuestionCodes
     * @return array|SurveyDynamic|null
     */
    public function getResponseValue($surveyId, $responseId)
    {
        $survey = \Survey::model()->findByPk($surveyId);
        $response = \SurveyDynamic::model($surveyId)->findByPk($responseId);

        if (isset($response)) {
            // Now map the response to the question codes if possible, duplicate question codes will result in the
            // old sidXgidXqid code for the second time the code is found
            $fieldmap = createFieldMap($survey, 'full', null, false, $response->attributes['startlanguage']);
            $output = array();
            foreach ($response->attributes as $key => $value) {
                $newKey = $key;
                if (array_key_exists($key, $fieldmap)) {
                    if (array_key_exists('title', $fieldmap[$key])) {
                        $code = $fieldmap[$key]['title'];
                        // Add subquestion code if needed
                        if (array_key_exists('aid', $fieldmap[$key]) && isset($fieldmap[$key]['aid']) && $fieldmap[$key]['aid'] != '') {
                            $code .= '_' . $fieldmap[$key]['aid'];
                        }
                        // Only add if the code does not exist yet and is not empty
                        if (!empty($code) && !array_key_exists($code, $output)) {
                            $newKey = $code;
                        }
                    }
                }
                //if an answer exists for this newKey and value use that instead
                $answer = \Answer::model()->getAnswerFromCode($fieldmap[$key]['qid'], $value, $response->attributes['startlanguage']);
                if ($answer !== null) {
	                $output[$newKey] = html_entity_decode($answer); //allow html to pass through eg for images
                } else {
                        $output[$newKey] = $value;
                }
            }

            // And return the mapped response, to further enhance we could add a method to the api that provides a
            // simple sort of fieldmap that returns qcode index array with group, question, subquestion,
            // possible answers, maybe even combined with relevance info so a plugin can handle display of the response
            return $output;
        }
    }


}
