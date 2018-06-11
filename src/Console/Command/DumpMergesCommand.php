<?php declare(strict_types=1);

namespace Symplify\ChangelogLinker\Console\Command;

use Nette\Utils\Strings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\ChangelogLinker\Analyzer\IdsAnalyzer;
use Symplify\ChangelogLinker\ChangeTree\Change;
use Symplify\ChangelogLinker\ChangeTree\ChangeSorter;
use Symplify\ChangelogLinker\ChangeTree\ChangeTree;
use Symplify\ChangelogLinker\Github\GithubApi;
use Symplify\ChangelogLinker\Github\PullRequestMessageFactory;
use Symplify\ChangelogLinker\Regex\RegexPattern;
use Symplify\PackageBuilder\Console\Command\CommandNaming;
use Symplify\PackageBuilder\Reflection\PrivatesAccessor;

/**
 * @inspired by https://github.com/weierophinney/changelog_generator
 */
final class DumpMergesCommand extends Command
{
    /**
     * @var string
     */
    private const OPTION_IN_CATEGORIES = 'in-categories';

    /**
     * @var string
     */
    private const OPTION_IN_PACKAGES = 'in-packages';

    /**
     * @var GithubApi
     */
    private $githubApi;

    /**
     * @var ChangeTree
     */
    private $changeTree;

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var PullRequestMessageFactory
     */
    private $pullRequestMessageFactory;

    /**
     * @var ChangeSorter
     */
    private $changeSorter;
    /**
     * @var IdsAnalyzer
     */
    private $idsAnalyzer;

    public function __construct(
        GithubApi $githubApi,
        ChangeTree $changeTree,
        SymfonyStyle $symfonyStyle,
        PullRequestMessageFactory $pullRequestMessageFactory,
        ChangeSorter $changeSorter,
        IdsAnalyzer $idsAnalyzer
    ) {
        $this->githubApi = $githubApi;
        $this->changeTree = $changeTree;
        $this->symfonyStyle = $symfonyStyle;
        $this->pullRequestMessageFactory = $pullRequestMessageFactory;
        $this->changeSorter = $changeSorter;
        $this->idsAnalyzer = $idsAnalyzer;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(CommandNaming::classToName(self::class));
        $this->setDescription(
            'Scans repository merged PRs, that are not in the CHANGELOG.md yet, and dumps them in changelog format.'
        );
        $this->addOption(
            self::OPTION_IN_CATEGORIES,
            null,
            InputOption::VALUE_NONE,
            'Print in Added/Changed/Fixed/Removed - detected from "Add", "Fix", "Removed" etc. keywords in merge title.'
        );

        $this->addOption(
            self::OPTION_IN_PACKAGES,
            null,
            InputOption::VALUE_NONE,
            'Print in groups in package names - detected from "[PackageName]" in merge title.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lastIdInChangelog = $this->idsAnalyzer->getLastIdInChangelog(getcwd() . '/CHANGELOG.md');

        $pullRequests = $this->githubApi->getClosedPullRequestsSinceId($lastIdInChangelog);

        if (count($pullRequests) === 0) {
            $this->symfonyStyle->note(
                sprintf('There are no new pull requests to be added since ID "%d".', $lastIdInChangelog)
            );

            // success
            return 0;
        }

        $this->loadPullRequestsToChangeTree($pullRequests);

        if (! $input->getOption(self::OPTION_IN_CATEGORIES) && ! $input->getOption(self::OPTION_IN_PACKAGES)) {
            $this->printAllChanges();

            // success
            return 0;
        }

        $this->printChangesWithHeadlines(
            $input->getOption(self::OPTION_IN_CATEGORIES),
            $input->getOption(self::OPTION_IN_PACKAGES),
            $this->getSortingPriority($input)
        );

        // success
        return 0;
    }

    /**
     * @param mixed[] $pullRequests
     */
    private function loadPullRequestsToChangeTree(array $pullRequests): void
    {
        foreach ($pullRequests as $pullRequest) {
            $pullRequestMessage = $this->pullRequestMessageFactory->createMessageFromPullRequest($pullRequest);
            $this->changeTree->addPullRequestMessage($pullRequestMessage);
        }
    }

    private function printAllChanges(): void
    {
        $this->symfonyStyle->newLine(1);

        foreach ($this->changeTree->getChanges() as $change) {
            $this->symfonyStyle->writeln($change->getMessage());
        }

        $this->symfonyStyle->newLine(1);
    }

    private function printChangesWithHeadlines(bool $withCategories, bool $withPackages, string $priority): void
    {
        $sortedChanges = $this->changeSorter->sortByCategoryAndPackage($this->changeTree->getChanges(), $priority);

        // only categories
        if ($withCategories && ! $withPackages) {
            $this->printChangesByCategories($sortedChanges);
            return;
        }

        // only packages
        if ($withPackages && ! $withCategories) {
            $this->printChangesByPackages($sortedChanges);
            return;
        }

        $this->printChangesByCategoriesAndPackages($sortedChanges, $priority);
    }

    /**
     * Detects the order in which "--in-packages" and "--in-categories" are called.
     * The first has a priority.
     */
    private function getSortingPriority(InputInterface $input): string
    {
        $rawOptions = (new PrivatesAccessor())->getPrivateProperty($input, 'options');

        foreach ($rawOptions as $name => $value) {
            if ($name === 'in-packages') {
                return 'packages';
            }
        }

        return 'categories';
    }

    /**
     * @param Change[] $changes
     */
    private function printChangesByPackages(array $changes): void
    {
        $previousPackage = '';
        foreach ($changes as $change) {
            if ($previousPackage !== $change->getPackage()) {
                $this->symfonyStyle->newLine(1);
                $this->symfonyStyle->writeln('### ' . $change->getPackage());
                $this->symfonyStyle->newLine(1);
            }

            $this->symfonyStyle->writeln($change->getMessage());

            $previousPackage = $change->getPackage();
        }

        $this->symfonyStyle->newLine(1);
        return;
    }

    /**
     * @param Change[] $changes
     */
    private function printChangesByCategories(array $changes): void
    {
        $previousCategory = '';
        foreach ($changes as $change) {
            if ($previousCategory !== $change->getCategory()) {
                $this->symfonyStyle->newLine(1);
                $this->symfonyStyle->writeln('### ' . $change->getCategory());
                $this->symfonyStyle->newLine(1);
            }

            $this->symfonyStyle->writeln($change->getMessage());

            $previousCategory = $change->getCategory();
        }

        $this->symfonyStyle->newLine(1);
    }

    /**
     * @param Change[] $changes
     */
    private function printChangesByCategoriesAndPackages(array $changes, string $priority): void
    {
        $previousPrimary = '';
        $previousSecondary = '';

        foreach ($changes as $change) {
            if ($priority === ChangeSorter::PRIORITY_PACKAGES) {
                $currentPrimary = $change->getPackage();
                $currentSecondary = $change->getCategory();
            } else {
                $currentPrimary = $change->getCategory();
                $currentSecondary = $change->getPackage();
            }

            $this->printHeadline($previousPrimary, $currentPrimary, $previousSecondary, $currentSecondary);

            $this->symfonyStyle->writeln($change->getMessage());

            $previousPrimary = $currentPrimary;
            $previousSecondary = $currentSecondary;
        }

        $this->symfonyStyle->newLine(1);
    }

    private function printHeadline(
        string $previousPrimary,
        string $currentPrimary,
        string $previousSecondary,
        string $currentSecondary
    ): void {
        $spaceAlreadyAdded = false;

        if ($previousPrimary !== $currentPrimary) {
            $this->symfonyStyle->newLine(1);
            $this->symfonyStyle->writeln('### ' . $currentPrimary);
            $this->symfonyStyle->newLine(1);
            $spaceAlreadyAdded = true;

            $previousSecondary = null;
        }

        if ($previousSecondary !== $currentSecondary) {
            if (! $spaceAlreadyAdded) {
                $this->symfonyStyle->newLine(1);
            }

            $this->symfonyStyle->writeln('#### ' . $currentSecondary);
            $this->symfonyStyle->newLine(1);
        }
    }
}