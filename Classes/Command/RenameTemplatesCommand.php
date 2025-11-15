<?php

namespace Praetorius\FluidRename\Command;

use Praetorius\FluidRename\Enum\RenameTemplateMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\TreeHelper;
use Symfony\Component\Console\Helper\TreeNode;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Parser\Patterns;
use TYPO3Fluid\Fluid\Core\Parser\TemplateProcessor\NamespaceDetectionTemplateProcessor;

#[AsCommand('fluid:rename:templates', 'Add new *.fluid.* file extension to Fluid template files')]
final class RenameTemplatesCommand extends Command
{
    public function __construct(private readonly PackageManager $packageManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'package',
                InputArgument::REQUIRED,
                'TYPO3 package name (either extension name or composer package name)',
            )
            ->addOption(
                'include-tests',
                null,
                InputOption::VALUE_NONE,
                'include Tests/ directory',
            )
            ->addOption(
                'tree',
                null,
                InputOption::VALUE_NONE,
                'show detected template files in a tree instead of a list',
            )
            ->addOption(
                'extensions',
                null,
                InputOption::VALUE_OPTIONAL,
                'comma-separated list of file extensions that should be considered potential Fluid templates',
                'html,txt,xml,json',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageName = $input->getArgument('package');
        try {
            $package = $this->packageManager->getPackage($packageName);
        } catch (UnknownPackageException $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }

        $fileExtensions = GeneralUtility::trimExplode(',', $input->getOption('extensions'), true);
        if ($fileExtensions === []) {
            $output->writeln('No file extensions have been specified.');
            return Command::FAILURE;
        }
        $fileExtensionPatterns = array_map(
            fn(string $ext): string => '*.' . $ext,
            $fileExtensions,
        );

        // Find potential Fluid templates in package
        $finder = new Finder();
        $potentialTemplates = $finder
            ->files()
            ->in($package->getPackagePath())
            ->exclude([
                'Classes',
                'node_modules',
                'vendor',
            ])
            ->notName([
                '*.fluid.*',
                'composer.json',
                'package.json',
                'package-lock.json',
                'guides.xml',
                'LICENSE.txt',
                'README.txt',
                'CREDITS.txt',
                '*.rst.txt',
                'ext_conf_template.txt',
            ])
            ->name($fileExtensionPatterns);
        if (!$input->getOption('include-tests')) {
            $potentialTemplates->exclude('Tests');
        }

        $detectedTemplates = $userConfirmTemplates = [];
        foreach ($potentialTemplates as $file) {
            // Skip empty files
            if ($file->getSize() === 0) {
                continue;
            }
            // Read snippet from file and check for Fluid markers
            $fileObject = $file->openFile();
            $snippet = $fileObject->fread(1024);
            if ($this->detectFluidTemplate($snippet)) {
                $detectedTemplates[] = $file;
            } else {
                $userConfirmTemplates[] = $file;
            }
        }

        // Renaming process for confirmed Fluid templates
        if ($detectedTemplates !== []) {
            $output->writeln('');
            if ($input->getOption('tree')) {
                $tree = TreeHelper::createTree($output, $this->createFileTree($package->getPackagePath(), $detectedTemplates));
                $tree->render();
            } else {
                $output->writeln($this->createFileList($package->getPackagePath(), $detectedTemplates));
            }
            $output->writeln(sprintf(
                '%d guaranteed template file(s) found in "%s". Rename automatically?',
                count($detectedTemplates),
                $packageName,
            ));
            $mode = $this->determineRenameMode($input, $output, RenameTemplateMode::RenameAll);
            $this->renameTemplateFiles($input, $output, $package, $detectedTemplates, $mode);
        }

        // Renaming process for questionable Fluid templates
        if ($userConfirmTemplates !== []) {
            $output->writeln('');
            if ($input->getOption('tree')) {
                $tree = TreeHelper::createTree($output, $this->createFileTree($package->getPackagePath(), $userConfirmTemplates));
                $tree->render();
            } else {
                $output->writeln($this->createFileList($package->getPackagePath(), $userConfirmTemplates));
            }
            $output->writeln(sprintf(
                '%d potential template file(s) in "%s" should be checked manually:',
                count($userConfirmTemplates),
                $packageName,
            ));
            $mode = $this->determineRenameMode($input, $output, RenameTemplateMode::ConfirmInteractively);
            $this->renameTemplateFiles($input, $output, $package, $userConfirmTemplates, $mode);
        }

        if ($detectedTemplates === [] && $userConfirmTemplates === []) {
            $output->writeln(sprintf(
                'No potential template files found in "%s".',
                $packageName,
            ));
        }

        return Command::SUCCESS;
    }

    private function determineRenameMode(InputInterface $input, OutputInterface $output, RenameTemplateMode $default): RenameTemplateMode
    {
        $options = [];
        foreach (RenameTemplateMode::cases() as $option) {
            $options[$option->value] = $option->getLabel();
        }
        $question = new ChoiceQuestion(
            sprintf('Rename automatically? (default: %s)', $default->value),
            $options,
            $default->value,
        );
        $userAnswer = (new QuestionHelper())->ask($input, $output, $question);
        return RenameTemplateMode::from($userAnswer);
    }

    /**
     * @param SplFileInfo[] $files
     */
    private function renameTemplateFiles(InputInterface $input, OutputInterface $output, PackageInterface $package, array $files, RenameTemplateMode $mode): void
    {
        if ($files === []) {
            return;
        }
        switch ($mode) {
            case RenameTemplateMode::SkipAll:
                $output->writeln('Skipping ...');
                return;

            case RenameTemplateMode::RenameAll:
                $output->writeln('New file names:');
                break;

            case RenameTemplateMode::ConfirmInteractively:
                $output->writeln('Files to check:');
                break;
        }
        foreach ($files as $file) {
            $oldPath = $file->getPathname();
            $newPath = $file->getPath() . '/' . $file->getFilenameWithoutExtension() . '.fluid.' . $file->getExtension();
            if ($mode === RenameTemplateMode::RenameAll) {
                $output->writeln('  ' . str_replace($package->getPackagePath(), '', $newPath));
                rename($oldPath, $newPath);
            } else {
                $question = new ConfirmationQuestion(sprintf(
                    '  %s to %s. Rename now? (y/n, default: n)',
                    str_replace($package->getPackagePath(), '', $oldPath),
                    basename($newPath),
                ), false);
                if ((new QuestionHelper())->ask($input, $output, $question)) {
                    rename($oldPath, $newPath);
                } else {
                    $output->writeln('    Skipping ...');

                }
            }
        }
    }

    /**
     * @param SplFileInfo[] $files
     */
    private function createFileTree(string $rootPath, array $files, bool $fullRootPath = true): TreeNode
    {
        $subTrees = [];
        foreach ($files as $file) {
            $relativePath = substr((string)$file, strlen($rootPath));
            $fragments = explode('/', $relativePath);
            $subRoot = $rootPath . array_shift($fragments) . ($fragments !== [] ? '/' : '');
            $subTrees[$subRoot] ??= [];
            if ($fragments !== []) {
                $subTrees[$subRoot][] = $file;
            }
        }
        $nodeLabel = $fullRootPath
            ? $rootPath
            : basename($rootPath) . (str_ends_with($rootPath, '/') ? '/' : '');
        $root = new TreeNode($nodeLabel);
        foreach ($subTrees as $subRoot => $subFiles) {
            $root->addChild($this->createFileTree($subRoot, $subFiles, false));
        }
        return $root;
    }

    /**
     * @param SplFileInfo[] $files
     */
    private function createFileList(string $rootPath, array $files): array
    {
        $output = [$rootPath];
        foreach ($files as $file) {
            $relativePath = substr((string)$file, strlen($rootPath));
            $output[] = '  ' . $relativePath;
        }
        return $output;
    }

    private function detectFluidTemplate(string $content): bool
    {
        $detectionPatterns = [
            // xmlns namespace
            '/' . preg_quote(Patterns::NAMESPACEPREFIX, '/') . '/',
            // alternative namespace declaration
            NamespaceDetectionTemplateProcessor::NAMESPACE_DECLARATION,
            // Inline ViewHelper call in f: namespace
            '/{f:[a-zA-Z0-9\\.]+\(/',
            // Tag ViewHelper call in f: namespace
            '/<f:[a-zA-Z0-9\\.]+[\s>]/',
        ];
        foreach ($detectionPatterns as $pattern) {
            if (preg_match($pattern, $content) > 0) {
                return true;
            }
        }
        return false;
    }
}
