<?php

namespace SilverStripe\Subsites\Tasks;

use InvalidArgumentException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Subsites\Pages\SubsitesVirtualPage;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Handy alternative to copying pages when creating a subsite through the UI.
 *
 * Can be used to batch-add new pages after subsite creation, or simply to
 * process a large site outside of the UI.
 *
 * Example: sake dev/tasks/SubsiteCopyPagesTask from=<subsite-source> to=<subsite-target>
 *
 * @package subsites
 */
class SubsiteCopyPagesTask extends BuildTask
{
    protected static string $commandName = 'SubsiteCopyPagesTask';

    protected string $title = 'Copy pages to different subsite';
    protected static string $description = '';

    public function getOptions(): array
    {
        return [
            new InputOption('from', null, InputOption::VALUE_REQUIRED, 'ID of the subsite to copy pages from'),
            new InputOption('to', null, InputOption::VALUE_REQUIRED, 'ID of the subsite to copy pages to'),
            new InputOption('virtual', null, InputOption::VALUE_NONE, 'Create virtual pages instead of duplicates'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $subsiteFromId = $input->getOption('from');
        if (!is_numeric($subsiteFromId)) {
            throw new InvalidArgumentException('Missing "from" parameter');
        }
        $subsiteFrom = DataObject::get_by_id(Subsite::class, $subsiteFromId);
        if (!$subsiteFrom) {
            throw new InvalidArgumentException('Subsite not found');
        }

        $subsiteToId = $input->getOption('to');
        if (!is_numeric($subsiteToId)) {
            throw new InvalidArgumentException('Missing "to" parameter');
        }
        $subsiteTo = DataObject::get_by_id(Subsite::class, $subsiteToId);
        if (!$subsiteTo) {
            throw new InvalidArgumentException('Subsite not found');
        }

        $useVirtualPages = (bool) $input->getOption('virtual');

        Subsite::changeSubsite($subsiteFrom);

        // Copy data from this template to the given subsite. Does this using an iterative depth-first search.
        // This will make sure that the new parents on the new subsite are correct, and there are no funny
        // issues with having to check whether or not the new parents have been added to the site tree
        // when a page, etc, is duplicated
        $stack = [[0, 0]];
        while (count($stack ?? []) > 0) {
            list($sourceParentID, $destParentID) = array_pop($stack);

            $children = Versioned::get_by_stage(SiteTree::class, 'Live', "\"ParentID\" = $sourceParentID", '');

            if ($children) {
                foreach ($children as $child) {
                    if ($useVirtualPages) {
                        $childClone = new SubsitesVirtualPage();
                        $childClone->writeToStage('Stage');
                        $childClone->CopyContentFromID = $child->ID;
                        $childClone->SubsiteID = $subsiteTo->ID;
                    } else {
                        $childClone = $child->duplicateToSubsite($subsiteTo->ID, true);
                    }

                    $childClone->ParentID = $destParentID;
                    $childClone->writeToStage('Stage');
                    $childClone->copyVersionToStage('Stage', 'Live');
                    array_push($stack, [$child->ID, $childClone->ID]);

                    $output->writeln(sprintf('Copied "%s" (#%d, %s)', $child->Title, $child->ID, $child->Link()));
                }
            }

            unset($children);
        }

        return Command::SUCCESS;
    }
}
