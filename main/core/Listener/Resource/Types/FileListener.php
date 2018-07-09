<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Listener\Resource\Types;

use Claroline\AppBundle\Persistence\ObjectManager;
use Claroline\CoreBundle\Entity\Resource\AbstractResourceEvaluation;
use Claroline\CoreBundle\Entity\Resource\Directory;
use Claroline\CoreBundle\Entity\Resource\File;
use Claroline\CoreBundle\Entity\Resource\ResourceNode;
use Claroline\CoreBundle\Entity\Workspace\Workspace;
use Claroline\CoreBundle\Event\CreateResourceEvent;
use Claroline\CoreBundle\Event\CustomActionResourceEvent;
use Claroline\CoreBundle\Event\GenericDataEvent;
use Claroline\CoreBundle\Event\LoadFileEvent;
use Claroline\CoreBundle\Event\Resource\CopyResourceEvent;
use Claroline\CoreBundle\Event\Resource\DeleteResourceEvent;
use Claroline\CoreBundle\Event\Resource\DownloadResourceEvent;
use Claroline\CoreBundle\Event\Resource\File\EncodeFileEvent;
use Claroline\CoreBundle\Event\Resource\File\PlayFileEvent;
use Claroline\CoreBundle\Event\Resource\LoadResourceEvent;
use Claroline\CoreBundle\Event\Resource\OpenResourceEvent;
use Claroline\CoreBundle\Form\FileType;
use Claroline\CoreBundle\Library\Utilities\FileSystem;
use Claroline\CoreBundle\Library\Utilities\ZipArchive;
use Claroline\CoreBundle\Listener\NoHttpRequestException;
use Claroline\CoreBundle\Manager\Resource\ResourceEvaluationManager;
use Claroline\CoreBundle\Manager\ResourceManager;
use Claroline\CoreBundle\Manager\WorkspaceManager;
use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\File as SfFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @DI\Service("claroline.listener.file_listener")
 */
class FileListener implements ContainerAwareInterface
{
    /** @var ContainerInterface */
    private $container;
    /** @var RequestStack */
    private $request;
    /** @var KernelInterface */
    private $httpKernel;
    /** @var TokenStorageInterface */
    private $tokenStorage;
    /** @var ObjectManager */
    private $om;
    /** @var ResourceManager */
    private $resourceManager;
    /** @var WorkspaceManager */
    private $workspaceManager;
    /** @var string */
    private $filesDir;
    /** @var ResourceEvaluationManager */
    private $resourceEvalManager;

    /**
     * @DI\InjectParams({
     *     "container" = @DI\Inject("service_container")
     * })
     *
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
        $this->resourceManager = $container->get('claroline.manager.resource_manager');
        $this->workspaceManager = $container->get('claroline.manager.workspace_manager');
        $this->om = $container->get('claroline.persistence.object_manager');
        $this->tokenStorage = $container->get('security.token_storage');
        $this->request = $container->get('request_stack');
        $this->httpKernel = $container->get('http_kernel');
        $this->filesDir = $container->getParameter('claroline.param.files_directory');
        $this->resourceEvalManager = $container->get('claroline.manager.resource_evaluation_manager');
    }

    /**
     * @DI\Observe("create_file")
     *
     * @param CreateResourceEvent $event
     */
    public function onCreate(CreateResourceEvent $event)
    {
        $request = $this->container->get('request_stack')->getMasterRequest();
        $form = $this->container->get('form.factory')->create(new FileType(true), new File());
        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->handleFileCreation($form, $event);
        }

        $content = $this->container->get('templating')->render(
            'ClarolineCoreBundle:resource:create_form.html.twig',
            [
                'form' => $form->createView(),
                'resourceType' => $event->getResourceType(),
            ]
        );
        $event->setErrorFormContent($content);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("create_api_file")
     *
     * @param CreateResourceEvent $event
     *
     * @todo merge with onCreate
     */
    public function onApiCreate(CreateResourceEvent $event)
    {
        $form = new FileType(true);
        $form->enableApi();
        $form = $this->container->get('form.factory')->create($form, new File());
        $form->submit($this->container->get('request_stack')->getMasterRequest());

        if ($form->isValid()) {
            $this->handleFileCreation($form, $event);
        }

        $event->setErrorFormContent($form);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("delete_file")
     *
     * @param DeleteResourceEvent $event
     */
    public function onDelete(DeleteResourceEvent $event)
    {
        $pathName = $this->container->getParameter('claroline.param.files_directory').
            DIRECTORY_SEPARATOR.
            $event->getResource()->getHashName();

        if (file_exists($pathName)) {
            $event->setFiles([$pathName]);
        }

        $event->stopPropagation();
    }

    /**
     * @DI\Observe("copy_file")
     *
     * @param CopyResourceEvent $event
     */
    public function onCopy(CopyResourceEvent $event)
    {
        $newFile = $this->copy($event->getResource(), $event->getParent());
        $event->setCopy($newFile);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("download_file")
     *
     * @param DownloadResourceEvent $event
     */
    public function onDownload(DownloadResourceEvent $event)
    {
        $file = $event->getResource();
        $hash = $file->getHashName();
        $event->setItem(
            $this->container
                ->getParameter('claroline.param.files_directory').DIRECTORY_SEPARATOR.$hash
        );
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("load_file")
     *
     * @param LoadResourceEvent $event
     */
    public function onLoad(LoadResourceEvent $event)
    {
        $resource = $event->getResource();
        $additionalFileData = [];

        /** @var LoadFileEvent $loadEvent */
        $loadEvent = $this->container->get('claroline.event.event_dispatcher')
            ->dispatch(
                $this->generateEventName($resource->getResourceNode(), 'load_file_'),
                'Resource\File\LoadFile',
                [$resource]
            );

        // with this method, file types that does not require additional data
        // will dispatch standard event + fallback event
        if (!empty($loadEvent->getAdditionalData())) {
            $additionalFileData = $loadEvent->getAdditionalData();
        } else {
            // no additional data, try to dispatch the fallback event
            /** @var LoadFileEvent $fallBackEvent */
            $fallBackEvent = $this->container->get('claroline.event.event_dispatcher')->dispatch(
                $this->generateEventName($resource->getResourceNode(), 'play_file_', true),
                'Resource\File\LoadFile',
                [$resource]
            );

            if (!empty($fallBackEvent->getAdditionalData())) {
                $additionalFileData = $fallBackEvent->getAdditionalData();
            }
        }

        $event->setAdditionalData(array_merge([
            // common file data
            'file' => $this->container->get('claroline.serializer.file')->serialize($resource),
        ], $additionalFileData));
    }

    /**
     * @DI\Observe("open_file")
     *
     * @param OpenResourceEvent $event
     */
    public function onOpen(OpenResourceEvent $event)
    {
        $ds = DIRECTORY_SEPARATOR;
        $resource = $event->getResource();

        /** @var PlayFileEvent $playEvent */
        $playEvent = $this->container->get('claroline.event.event_dispatcher')
            ->dispatch(
                $this->generateEventName($resource->getResourceNode(), 'play_file_'),
                'Resource\File\PlayFile',
                [$resource]
            );

        if ($playEvent->getResponse() instanceof Response) {
            $response = $playEvent->getResponse();
        } else {
            $fallBackPlayEvent = $this->container->get('claroline.event.event_dispatcher')->dispatch(
                $this->generateEventName($resource->getResourceNode(), 'play_file_', true),
                'Resource\File\PlayFile',
                [$resource]
            );
            if ($fallBackPlayEvent->getResponse() instanceof Response) {
                $response = $fallBackPlayEvent->getResponse();
            } else {
                $item = $this->container
                    ->getParameter('claroline.param.files_directory').$ds.$resource->getHashName();
                $file = file_get_contents($item);
                $response = new Response();
                $response->setContent($file);
                $response->headers->set(
                    'Content-Transfer-Encoding',
                    'octet-stream'
                );
                $response->headers->set(
                    'Content-Type',
                    'application/force-download'
                );
                $response->headers->set(
                    'Content-Disposition',
                    'attachment; filename='.urlencode($resource->getResourceNode()->getName())
                );
                $response->headers->set(
                    'Content-Type',
                    'application/'.pathinfo($item, PATHINFO_EXTENSION)
                );
                $response->headers->set(
                    'Connection',
                    'close'
                );
            }
        }
        $event->setResponse($response);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("update_file_file")
     *
     * @param CustomActionResourceEvent $event
     *
     * @throws NoHttpRequestException
     */
    public function onUpdateFile(CustomActionResourceEvent $event)
    {
        if (!$this->request) {
            throw new NoHttpRequestException();
        }

        $params = [];
        $params['_controller'] = 'ClarolineCoreBundle:File:updateFileForm';
        $params['file'] = $event->getResource()->getId();
        $subRequest = $this->request->getCurrentRequest()->duplicate([], null, $params);
        $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        $event->setResponse($response);
    }

    private function generateEventName(ResourceNode $node, $eventPrefix, $useBaseType = false)
    {
        $mimeType = $node->getMimeType();

        if ($useBaseType) {
            $mimeElements = explode('/', $mimeType);
            $suffix = strtolower($mimeElements[0]);
        } else {
            $suffix = $mimeType;
        }

        $eventName = strtolower(str_replace('/', '_', $eventPrefix.$suffix));
        $eventName = str_replace('"', '', $eventName);

        return $eventName;
    }

    /**
     * Copies a file (no persistence).
     *
     * @param File         $resource
     * @param ResourceNode $destParent
     *
     * @return File
     */
    private function copy(File $resource, ResourceNode $destParent)
    {
        $ds = DIRECTORY_SEPARATOR;
        $workspace = $destParent->getWorkspace();
        $newFile = new File();
        $newFile->setSize($resource->getSize());
        $newFile->setName($resource->getName());
        $newFile->setMimeType($resource->getMimeType());
        $hashName = 'WORKSPACE_'.$workspace->getId().
            $ds.
            $this->container->get('claroline.utilities.misc')->generateGuid().
            '.'.
            pathinfo($resource->getHashName(), PATHINFO_EXTENSION);
        $newFile->setHashName($hashName);
        $filePath = $this->container->getParameter('claroline.param.files_directory').$ds.$resource->getHashName();
        $newPath = $this->container->getParameter('claroline.param.files_directory').$ds.$hashName;
        $workspaceDir = $this->filesDir.$ds.'WORKSPACE_'.$workspace->getId();

        if (!is_dir($workspaceDir)) {
            mkdir($workspaceDir);
        }

        try {
            copy($filePath, $newPath);
        } catch (\Exception $e) {
            //do nothing yet
            //maybe log an error
        }

        return $newFile;
    }

    /**
     * @DI\Observe("generate_resource_user_evaluation_file")
     *
     * @param GenericDataEvent $event
     */
    public function onGenerateResourceTracking(GenericDataEvent $event)
    {
        $data = $event->getData();
        $node = $data['resourceNode'];
        $user = $data['user'];
        $startDate = $data['startDate'];

        $logs = $this->resourceEvalManager->getLogsForResourceTracking(
            $node,
            $user,
            ['resource-read'],
            $startDate
        );
        $nbLogs = count($logs);

        if ($nbLogs > 0) {
            $this->om->startFlushSuite();
            $tracking = $this->resourceEvalManager->getResourceUserEvaluation($node, $user);
            $tracking->setDate($logs[0]->getDateLog());
            $tracking->setStatus(AbstractResourceEvaluation::STATUS_OPENED);
            $tracking->setNbOpenings($nbLogs);
            $this->om->persist($tracking);
            $this->om->endFlushSuite();
        }
        $event->stopPropagation();
    }

    private function unzip($archivePath, ResourceNode $root, $published = true)
    {
        $extractPath = $this->container->get('claroline.config.platform_config_handler')->getParameter('tmp_dir')
            .DIRECTORY_SEPARATOR.
            $this->container->get('claroline.utilities.misc')->generateGuid();

        $archive = new ZipArchive();

        if (true === $archive->open($archivePath)) {
            $archive->extractTo($extractPath);
            $archive->close();
            $this->om->startFlushSuite();
            $perms = $this->container->get('claroline.manager.rights_manager')->getCustomRoleRights($root);
            $resources = $this->uploadDir($extractPath, $root, $perms, true, $published);
            $this->om->endFlushSuite();
            $fs = new FileSystem();
            $fs->rmdir($extractPath, true);

            return $resources;
        }

        throw new \Exception("The archive {$archivePath} can't be opened");
    }

    private function uploadDir(
        $dir,
        ResourceNode $parent,
        array $perms,
        $first = false,
        $published = true
    ) {
        $resources = [];
        $iterator = new \DirectoryIterator($dir);

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $resources[] = $this->uploadFile($item, $parent, $perms, $published);
            }

            if ($item->isDir() && !$item->isDot()) {
                //create new dir
                $directory = new Directory();
                $directory->setName($item->getBasename());
                $resources[] = $this->resourceManager->create(
                    $directory,
                    $this->resourceManager->getResourceTypeByName('directory'),
                    $this->tokenStorage->getToken()->getUser(),
                    $parent->getWorkspace(),
                    $parent,
                    null,
                    $perms,
                    $published
                );

                $this->uploadDir(
                    $dir.DIRECTORY_SEPARATOR.$item->getBasename(),
                    $directory->getResourceNode(),
                    $perms,
                    false,
                    $published
                );
            }
        }

        // set order manually as we are inside a flush suite
        for ($i = 0, $count = count($resources); $i < $count; ++$i) {
            $resources[$i]->getResourceNode()->setIndex($i + 1);
        }

        return $resources;
    }

    private function uploadFile(
        \DirectoryIterator $file,
        ResourceNode $parent,
        array $perms,
        $published = true
    ) {
        $workspaceId = $parent->getWorkspace()->getId();
        $entityFile = new File();
        $fileName = $file->getFilename();
        $size = @filesize($file);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $mimeType = $this->container->get('claroline.utilities.mime_type_guesser')->guess($extension);
        $hashName = 'WORKSPACE_'.$workspaceId.
            DIRECTORY_SEPARATOR.
            $this->container->get('claroline.utilities.misc')->generateGuid().
            '.'.
            $extension;
        $destination = $this->container->getParameter('claroline.param.files_directory').
            DIRECTORY_SEPARATOR.
            $hashName;
        copy($file->getPathname(), $destination);
        $entityFile->setSize($size);
        $entityFile->setName($fileName);
        $entityFile->setHashName($hashName);
        $entityFile->setMimeType($mimeType);

        return $this->resourceManager->create(
            $entityFile,
            $this->resourceManager->getResourceTypeByName('file'),
            $this->tokenStorage->getToken()->getUser(),
            $parent->getWorkspace(),
            $parent,
            null,
            $perms,
            $published
        );
    }

    /**
     * @deprecated
     */
    public function createFile(
        File $file,
        SfFile $tmpFile,
        $fileName,
        $mimeType,
        Workspace $workspace = null
    ) {
        return $this->container->get('claroline.manager.file_manager')->create($file, $tmpFile, $fileName, $mimeType, $workspace);
    }

    private function handleFileCreation($form, CreateResourceEvent $event)
    {
        $workspace = $event->getParent()->getWorkspace();
        $workspaceDir = $this->workspaceManager->getStorageDirectory($workspace);
        $isStorageLeft = $this->resourceManager->checkEnoughStorageSpaceLeft(
            $workspace,
            $form->get('file')->getData()
        );

        if (!$isStorageLeft) {
            $this->resourceManager->addStorageExceededFormError(
                $form,
                filesize($form->get('file')->getData()),
                $workspace
            );
        } else {
            //check if there is enough space left
            //$file is the entity
            //$tmpFile is the other file
            $file = $form->getData();
            $tmpFile = $form->get('file')->getData();

            //the tmpFile may require some encoding.
            if ('none' !== $event->getEncoding()) {
                $tmpFile = $this->encodeFile($tmpFile, $event->getEncoding());
            }

            $published = $form->get('published')->getData();
            $event->setPublished($published);
            $fileName = $tmpFile->getClientOriginalName();
            $ext = strtolower($tmpFile->getClientOriginalExtension());
            $mimeType = $this->container->get('claroline.utilities.mime_type_guesser')->guess($ext);

            if (!is_dir($workspaceDir)) {
                mkdir($workspaceDir);
            }

            if ('zip' === pathinfo($fileName, PATHINFO_EXTENSION) && $form->get('uncompress')->getData()) {
                $roots = $this->unzip($tmpFile, $event->getParent(), $published);
                $event->setResources($roots);

                //do not process the resources afterwards because nodes have been created with the unzip function.
                $event->setProcess(false);
                $event->stopPropagation();
            } else {
                $file = $this->container->get('claroline.manager.file_manager')->create(
                    $file,
                    $tmpFile,
                    $fileName,
                    $mimeType,
                    $workspace
                );
                $event->setResources([$file]);
                $event->stopPropagation();
            }
        }
    }

    /**
     * @param UploadedFile $file
     * @param $encoding
     *
     * @return UploadedFile
     */
    private function encodeFile(UploadedFile $file, $encoding)
    {
        // I'm not sure this event is really needed
        // and if it is should better be a File event than a Resource file event
        $eventName = 'encode_file_'.$encoding;

        /** @var EncodeFileEvent $encodeEvent */
        $encodeEvent = $this->container->get('claroline.event.event_dispatcher')->dispatch($eventName, 'Resource\File\EncodeFile', [$file]);

        return $encodeEvent->getFile();
    }
}