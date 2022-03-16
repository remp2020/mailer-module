<?php
declare(strict_types=1);

namespace Remp\MailerModule\Models\ContentGenerator;

use Nette\Database\IRow;
use Remp\MailerModule\Models\ContentGenerator\Engine\EngineFactory;
use Remp\MailerModule\Models\ContentGenerator\Replace\IReplace;
use Remp\MailerModule\Models\MailTemplate;
use Remp\MailerModule\Models\MailTranslator;

class ContentGenerator
{
    private EngineFactory $engineFactory;

    private MailTranslator $mailTranslator;

    private $time;

    /** @var IReplace[] */
    private $replaceList = [];

    public function __construct(
        EngineFactory $engineFactory,
        MailTranslator $mailTranslator
    ) {
        $this->engineFactory = $engineFactory;
        $this->mailTranslator = $mailTranslator;

        $this->time = new \DateTime();
    }

    public function register(IReplace $replace): void
    {
        $this->replaceList[] = $replace;
    }

    public function render(GeneratorInput $generatorInput): MailContent
    {
        $params = $generatorInput->params();

        $template = $this->mailTranslator->translateTemplate($generatorInput->template(), $generatorInput->locale());
        $layout = $this->mailTranslator->translateLayout($generatorInput->layout(), $generatorInput->locale());

        if (isset($params['snippets'])) {
            $params['snippets'] = $this->mailTranslator->translateSnippets($params['snippets'], $generatorInput->locale());
        }

        $htmlBody = $this->generate($template->getHtmlBody(), $params);
        $html = $this->wrapLayout($template->getSubject(), $htmlBody, $layout->getHtml(), $params);

        $textBody = $this->generate($template->getTextBody(), $params);
        $text = $this->wrapLayout($template->getSubject(), $textBody, $layout->getText(), $params);

        foreach ($this->replaceList as $replace) {
            $html = $replace->replace($html, $generatorInput);
            $text = $replace->replace($text, $generatorInput);
        }

        return new MailContent($html, $text, $template->getSubject());
    }

    public function getEmailParams(GeneratorInput $generatorInput, array $emailParams): array
    {
        $outputParams = [];

        foreach ($emailParams as $name => $value) {
            foreach ($this->replaceList as $replace) {
                $value = $replace->replace((string)$value, $generatorInput);
            }
            $outputParams[$name] = $value;
        }

        return $outputParams;
    }

    private function generate(string $bodyTemplate, array $params): string
    {
        $params['time'] = $this->time;

        return $this->engineFactory->engine()->render($bodyTemplate, $params);
    }

    private function wrapLayout(string $subject, string $renderedTemplateContent, string $layoutContent, array $params): string
    {
        if (!$layoutContent) {
            return $renderedTemplateContent;
        }
        $layoutParams = [
            'title' => $subject,
            'content' => $renderedTemplateContent,
            'time' => $this->time,
        ];
        $params = array_merge($layoutParams, $params);
        return $this->engineFactory->engine()->render($layoutContent, $params);
    }
}
