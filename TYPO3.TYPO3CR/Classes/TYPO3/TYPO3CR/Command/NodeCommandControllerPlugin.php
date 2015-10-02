<?php
namespace TYPO3\TYPO3CR\Command;

/*
 * This file is part of the TYPO3.TYPO3CR package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\QueryBuilder;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\ConsoleOutput;
use TYPO3\TYPO3CR\Domain\Factory\NodeFactory;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeType;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;

/**
 * Plugin for the TYPO3CR NodeCommandController which provides functionality for creating missing child nodes.
 *
 * @Flow\Scope("singleton")
 */
class NodeCommandControllerPlugin implements NodeCommandControllerPluginInterface
{
    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @var ConsoleOutput
     */
    protected $output;

    /**
     * Doctrine's Entity Manager. Note that "ObjectManager" is the name of the related
     * interface ...
     *
     * @Flow\Inject
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $entityManager;

    /**
     * @var array
     */
    protected $pluginConfigurations = array();

    /**
     * Returns a short description
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @return string A piece of text to be included in the overall description of the node:xy command
     */
    public static function getSubCommandShortDescription($controllerCommandName)
    {
        switch ($controllerCommandName) {
            case 'repair':
                return 'Check and fix possibly missing child nodes';
            break;
        }
    }

    /**
     * Returns a piece of description for the specific task the plugin solves for the specified command
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @return string A piece of text to be included in the overall description of the node:xy command
     */
    public static function getSubCommandDescription($controllerCommandName)
    {
        switch ($controllerCommandName) {
            case 'repair':
                return
                    '<u>Missing child nodes</u>' . PHP_EOL .
                    PHP_EOL .
                    'For all nodes (or only those which match the --node-type filter specified with this' . PHP_EOL .
                    'command) which currently don\'t have child nodes as configured by the node type\'s' . PHP_EOL .
                    'configuration new child nodes will be created.' . PHP_EOL;
            break;
        }
    }

    /**
     * A method which runs the task implemented by the plugin for the given command
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @param ConsoleOutput $output An instance of ConsoleOutput which can be used for output or dialogues
     * @param NodeType $nodeType Only handle this node type (if specified)
     * @param string $workspaceName Only handle this workspace (if specified)
     * @param boolean $dryRun If TRUE, don't do any changes, just simulate what you would do
     * @return void
     */
    public function invokeSubCommand($controllerCommandName, ConsoleOutput $output, NodeType $nodeType = null, $workspaceName = 'live', $dryRun = false)
    {
        $this->output = $output;
        $this->createMissingChildNodes($nodeType, $workspaceName, $dryRun);
    }

    /**
     * Performs checks for missing child nodes according to the node's auto-create configuration and creates
     * them if necessary.
     *
     * @param NodeType $nodeType Only for this node type, if specified
     * @param string $workspaceName Name of the workspace to consider
     * @param boolean $dryRun Simulate?
     * @return void
     */
    protected function createMissingChildNodes(NodeType $nodeType = null, $workspaceName, $dryRun)
    {
        if ($nodeType !== null) {
            $this->output->outputLine('Checking nodes of type "%s" for missing child nodes ...', array($nodeType->getName()));
            $this->createChildNodesByNodeType($nodeType, $workspaceName, $dryRun);
        } else {
            $this->output->outputLine('Checking for missing child nodes ...');
            foreach ($this->nodeTypeManager->getNodeTypes() as $nodeType) {
                /** @var NodeType $nodeType */
                if ($nodeType->isAbstract()) {
                    continue;
                }
                $this->createChildNodesByNodeType($nodeType, $workspaceName, $dryRun);
            }
        }
    }

    /**
     * Create missing child nodes for the given node type
     *
     * @param NodeType $nodeType
     * @param string $workspaceName
     * @param boolean $dryRun
     * @return void
     */
    protected function createChildNodesByNodeType(NodeType $nodeType, $workspaceName, $dryRun)
    {
        $createdNodesCount = 0;
        $nodeCreationExceptions = 0;

        $nodeTypes = $this->nodeTypeManager->getSubNodeTypes($nodeType->getName(), false);
        $nodeTypes[$nodeType->getName()] = $nodeType;

        if ($this->nodeTypeManager->hasNodeType((string)$nodeType)) {
            $nodeType = $this->nodeTypeManager->getNodeType((string)$nodeType);
            $nodeTypeNames[$nodeType->getName()] = $nodeType;
        } else {
            $this->output->outputLine('Node type "%s" does not exist', array((string)$nodeType));
            exit(1);
        }

        /** @var $nodeType NodeType */
        foreach ($nodeTypes as $nodeTypeName => $nodeType) {
            $childNodes = $nodeType->getAutoCreatedChildNodes();
            foreach ($this->getNodeDataByNodeTypeAndWorkspace($nodeTypeName, $workspaceName) as $nodeData) {
                $context = $this->nodeFactory->createContextMatchingNodeData($nodeData);
                $node = $this->nodeFactory->createFromNodeData($nodeData, $context);
                if (!$node instanceof NodeInterface) {
                    continue;
                }
                foreach ($childNodes as $childNodeName => $childNodeType) {
                    try {
                        $childNodeMissing = $node->getNode($childNodeName) ? false : true;
                        if ($childNodeMissing) {
                            if ($dryRun === false) {
                                $node->createNode($childNodeName, $childNodeType);
                                $this->output->outputLine('Auto created node named "%s" in "%s"', array($childNodeName, $node->getPath()));
                            } else {
                                $this->output->outputLine('Missing node named "%s" in "%s"', array($childNodeName, $node->getPath()));
                            }
                            $createdNodesCount++;
                        }
                    } catch (\Exception $exception) {
                        $this->output->outputLine('Could not create node named "%s" in "%s" (%s)', array($childNodeName, $node->getPath(), $exception->getMessage()));
                        $nodeCreationExceptions++;
                    }
                }
            }
        }

        if ($createdNodesCount !== 0 || $nodeCreationExceptions !== 0) {
            if ($dryRun === false) {
                $this->output->outputLine('Created %s new child nodes', array($createdNodesCount));

                if ($nodeCreationExceptions > 0) {
                    $this->output->outputLine('%s Errors occurred during child node creation', array($nodeCreationExceptions));
                }
            } else {
                $this->output->outputLine('%s missing child nodes need to be created', array($createdNodesCount));
            }
        }
    }

    /**
     * Creates a content context for given workspace
     *
     * @param string $workspaceName
     * @return \TYPO3\TYPO3CR\Domain\Service\Context
     */
    protected function createContext($workspaceName)
    {
        return $this->contextFactory->create(array(
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ));
    }

    /**
     * Retrieves all NodeData objects of a certain node type inside a given workspace.
     *
     * Shadow nodes are excluded, because they will be published when publishing the moved node.
     *
     * @param string $nodeType
     * @param string $workspaceName
     * @return array<NodeData>
     */
    protected function getNodeDataByNodeTypeAndWorkspace($nodeType, $workspaceName)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder->select('n')
            ->distinct()
            ->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
            ->where('n.nodeType = :nodeType')
            ->andWhere('n.workspace = :workspace')
            ->andWhere('n.movedTo IS NULL OR n.removed = :removed')
            ->setParameter('nodeType', $nodeType)
            ->setParameter('workspace', $workspaceName)
            ->setParameter('removed', false, \PDO::PARAM_BOOL);
        return $queryBuilder->getQuery()->getResult();
    }
}
