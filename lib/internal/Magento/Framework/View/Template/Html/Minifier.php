<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\View\Template\Html;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Template\Html\Minifier\Php;

class Minifier implements MinifierInterface
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * All inline HTML tags
     *
     * @var array
     */
    protected $inlineHtmlTags = [
        'b',
        'big',
        'i',
        'small',
        'tt',
        'abbr',
        'acronym',
        'cite',
        'code',
        'dfn',
        'em',
        'kbd',
        'strong',
        'samp',
        'var',
        'a',
        'bdo',
        'br',
        'img',
        'map',
        'object',
        'q',
        'span',
        'sub',
        'sup',
        'button',
        'input',
        'label',
        'select',
        'textarea',
        '\?',
    ];

    /**
     * @var Filesystem\Directory\WriteInterface
     */
    protected $htmlDirectory;

    /**
     * @var Filesystem\Directory\ReadFactory
     */
    protected $readFactory;

    /**
     * @param Filesystem $filesystem
     * @param Filesystem\Directory\ReadFactory $readFactory
     */
    public function __construct(
        Filesystem $filesystem,
        Filesystem\Directory\ReadFactory $readFactory
    ) {
        $this->filesystem = $filesystem;
        $this->htmlDirectory = $filesystem->getDirectoryWrite(DirectoryList::TMP_MATERIALIZATION_DIR);
        $this->readFactory = $readFactory;
    }

    /**
     * Return path to minified template file, or minify if file not exist
     *
     * @param string $file
     * @return string
     */
    public function getMinified($file)
    {
        $file = $this->htmlDirectory->getDriver()->getRealPathSafety($file);
        if (!$this->htmlDirectory->isExist($this->getRelativeGeneratedPath($file))) {
            $this->minify($file);
        }
        return $this->getPathToMinified($file);
    }

    /**
     * Return path to minified template file
     *
     * @param string $file
     * @return string
     */
    public function getPathToMinified($file)
    {
        return $this->htmlDirectory->getAbsolutePath($this->getRelativeGeneratedPath($file));
    }

    /**
     * Minify template file
     *
     * @param string $file
     * @return void
     */
    public function minify($file)
    {
        $dir = dirname($file);
        $fileName = basename($file);
        $content = $this->readFactory->create($dir)->readFile($fileName);
        $heredocs = null;

        // Safely minify PHP code and remove single-line PHP comments by using a parser.
        if (null !== $content) {
            $parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7);

            /**
             * Prevent problems with deeply nested ASTs if Xdebug is enabled.
             * @see https://github.com/nikic/PHP-Parser/blob/v4.4.0/doc/2_Usage_of_basic_components.markdown#bootstrapping
             */
            $nestingLevelConfigValue = ini_get('xdebug.max_nesting_level');

            if (false !== $nestingLevelConfigValue) {
                ini_set('xdebug.max_nesting_level', '3000');
            }

            try {
                $ast = $parser->parse($content);

                $traverser = new \PhpParser\NodeTraverser();
                $traverser->addVisitor(new Php\NodeVisitor());
                $ast = $traverser->traverse($ast);

                $prettyPrinter = new Php\PrettyPrinter();
                $content = $prettyPrinter->prettyPrintFile($ast);
                $heredocs = $prettyPrinter->getDelayedHeredocs();
            } catch (\Error $error) {
                // Some PHP code is seemingly invalid, or too complex.
            } finally {
                if (false !== $nestingLevelConfigValue) {
                    ini_set('xdebug.max_nesting_level', $nestingLevelConfigValue);
                }
            }
        }

        // Stash the heredocs now if the template could not be parsed.
        if (null === $heredocs) {
            $content = preg_replace_callback(
                '/<<<([A-z]+).*?\1\s*;/ims',
                function ($match) use (&$heredocs) {
                    $heredocs[] = $match[0];

                    return '__MINIFIED_HEREDOC__' .(count($heredocs) - 1);
                },
                $content
            );
        }

        // Remove insignificant spaces before closing HTML tags
        // (preserve one space after ]]>, and all spaces inside <pre> and <textarea> tags).
        $content = preg_replace(
            '#(?<!]]>)\s+</(?!(?>textarea|pre)\b)#',
            '</',
            // Remove redundant spaces after PHP tags that do not start with a print or condition statement,
            // and that do not contain any "?".
            preg_replace(
                '#((?:<\?php\s+(?!echo|print|if|elseif|else)[^\?]*)\?>)\s+#',
                '$1 ',
                // Remove single space in empty non-inline tags.
                preg_replace(
                    '#(?<!' . implode('|', $this->inlineHtmlTags) . ')\> \<#',
                    '><',
                    // Remove redundant spaces outside of tags in which they are relevant.
                    preg_replace(
                        '#(?ix)(?>[^\S ]\s*|\s{2,})(?=(?:(?:[^<]++|<(?!/?(?:textarea|pre|script)\b))*+)'
                        . '(?:<(?>textarea|pre|script)\b|\z))#',
                        ' ',
                        // Remove single-line comments in <script> tags, except for <![CDATA[ and ]]>.
                        // Do nothing if the "//" part is seemingly part of a string / URL / RegExp.
                        preg_replace(
                            '#(?<!:|\\\\|\\\|\'|"|/)//(?!/)(?!\s*\<\!\[)(?!\s*]]\>)[^\n\r]*'
                            . '(?!(?:(?:[^<]++|<(?!/?(?:script)\b))*+)(?:<(?>script)\b|\z))#',
                            '',
                            // Remove commented single-line PHP tags in <script> tags.
                            // Do nothing if the "//" part is seemingly part of a URL / RegExp.
                            preg_replace(
                                '#(?<!:|\\\)//[^\n\r]*(\<\?(php|=))[^\n\r]*(\s\?\>)[^\n\r]*'
                                . '(?!(?:(?:[^<]++|<(?!/?(?:script)\b))*+)(?:<(?>script)\b|\z))#',
                                '',
                                $content
                            )
                        )
                    )
                )
            )
        );

        // Restore the stashed heredocs.
        $content = preg_replace_callback(
            '/__MINIFIED_HEREDOC__(\d+)/ims',
            function ($match) use ($heredocs) {
                return $heredocs[(int)$match[1]];
            },
            $content
        );

        if (!$this->htmlDirectory->isExist()) {
            $this->htmlDirectory->create();
        }
        $this->htmlDirectory->writeFile($this->getRelativeGeneratedPath($file), rtrim($content));
    }

    /**
     * Gets the relative path of minified file to generation directory
     *
     * @param string $sourcePath
     * @return string
     */
    private function getRelativeGeneratedPath($sourcePath)
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getRelativePath($sourcePath);
    }
}
