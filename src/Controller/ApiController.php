<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Webhook;
use Packeton\Model\DownloadManager;
use Packeton\Model\PackageManager;
use Packeton\Service\Scheduler;
use Packeton\Webhook\HookBus;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route(defaults: ['_format' => 'json'])]
class ApiController extends AbstractController
{
    use ControllerTrait;

    public function __construct(
        protected ManagerRegistry $registry,
        protected DownloadManager $downloadManager,
        protected LoggerInterface $logger,
        protected ValidatorInterface $validator,
    ){}

    #[Route('/api/create-package', name: 'generic_create', methods: ['POST'])]
    public function createPackageAction(Request $request): Response
    {
        $payload = $this->getJsonPayload($request);

        if (!$payload || empty($url = $payload['repository']['url'] ?? null)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload repository->url parameter'], 406);
        }

        $package = new Package;
        if ($this->getUser() instanceof User) {
            $package->addMaintainer($this->getUser());
        }

        $package->setRepository($url);
        $this->container->get(PackageManager::class)->updatePackageUrl($package);
        $errors = $this->validator->validate($package, null, ['Create']);
        if (\count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }
        try {
            $em = $this->registry->getManager();
            $em->persist($package);
            $em->flush();

        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage(), ['exception', $e]);
            return new JsonResponse(['status' => 'error', 'message' => 'Error saving package'], 500);
        }

        return new JsonResponse(['status' => 'success'], 202);
    }

    #[Route('/api/github', name: 'github_postreceive')]
    #[Route('/api/bitbucket', name: 'bitbucket_postreceive')]
    #[Route('/api/update-package', name: 'generic_postreceive')]
    #[Route('/api/update-package/{name}', name: 'generic_named_postreceive', requirements: ['name' => '%package_name_regex%'])]
    public function updatePackageAction(Request $request, #[Vars] Package $package = null): Response
    {
        // parse the payload
        $payload = $this->getJsonPayload($request);

        if (!$payload && !$request->get('composer_package_name') && null === $package) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }

        $packages = [$package];
        // Get from query parameter.
        if ($packageNames = $request->get('composer_package_name')) {
            $packageNames = \explode(',', $packageNames);
            $repo = $this->registry->getRepository(Package::class);
            foreach ($packageNames as $packageName) {
                $packages = \array_merge($packages, $repo->findBy(['name' => $packageName]));
            }
        }

        $packages = \array_values(\array_filter($packages));

        if (isset($payload['project']['git_http_url'])) { // gitlab event payload
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['project']['git_http_url'];
	    } elseif (isset($payload['repository']['html_url']) && !isset($payload['repository']['url'])) { // gitea event payload https://docs.gitea.io/en-us/webhooks/
            $urlRegex = '{^(?:ssh://(git@|gitea@)|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['html_url'];
        } elseif (isset($payload['repository']['url'])) { // github/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
            $url = \str_replace('https://api.github.com/repos', 'https://github.com', $url);
        } elseif (isset($payload['repository']['links']['html']['href'])) { // bitbucket push event payload
            $urlRegex = '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['repository']['links']['html']['href'];
        } elseif (isset($payload['repository']['links']['clone'][0]['href'])) { // bitbucket on-premise
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = '';
            foreach ($payload['repository']['links']['clone'] as $id => $data) {
                if ($data['name'] == 'ssh') {
                    $url = $data['href'];
                    break;
                }
            }
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket post hook (deprecated)
            $urlRegex = '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['canon_url'] . $payload['repository']['absolute_url'];
        } elseif (isset($payload['composer']['package_name'])) { // custom webhook
            $packages = [];
            $packageNames = (array) $payload['composer']['package_name'];
            $repo = $this->registry->getRepository(Package::class);
            foreach ($packageNames as $packageName) {
                $packages = \array_merge($packages, $repo->findBy(['name' => $packageName]));
            }
        } elseif (empty($packages)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid payload'], 406);
        }

        if ($packages) {
            return $this->schedulePostJobs($packages);
        }

        // Use the custom regex
        if (isset($payload['packeton']['regex'])) {
            $urlRegex = $payload['packeton']['regex'];
        }

        return $this->receivePost($request, $url, $urlRegex);
    }


    #[Route('/api/packages/{name}', name: 'api_edit_package', requirements: ['name' => '%package_name_regex%'], methods: ['PUT'])]
    public function editPackageAction(Request $request, #[Vars] Package $package): Response
    {
        $user = $this->getUser();
        if (!$package->getMaintainers()->contains($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException();
        }

        $payload = $this->getJsonPayload($request);

        $package->setRepository($payload['repository']);
        $this->container->get(PackageManager::class)->updatePackageUrl($package);
        $errors = $this->validator->validate($package, null, ["Update"]);
        if (\count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }

        $package->setCrawledAt(null);

        $em = $this->registry->getManager();
        $em->persist($package);
        $em->flush();

        return new JsonResponse(['status' => 'success'], 200);
    }


    #[Route('/downloads/{name}', name: 'track_download', requirements: ['name' => '%package_name_regex%'], methods: ['POST'])]
    public function trackDownloadAction(Request $request, $name): Response
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 200);
        }

        $this->downloadManager->addDownloads(['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $request->getClientIp()]);

        return new JsonResponse(['status' => 'success'], 201);
    }


    #[Route('/jobs/{id}', name: 'get_job', requirements: ['id' => '[a-f0-9]+'], methods: ['GET'])]
    public function getJobAction(string $id): Response
    {
        return new JsonResponse($this->container->get(Scheduler::class)->getJobStatus($id), 200);
    }

    /**
     * Expects a json like:
     *
     * {
     *     "downloads": [
     *         {"name": "foo/bar", "version": "1.0.0.0"},
     *         // ...
     *     ]
     * }
     *
     * The version must be the normalized one
     * @inheritDoc
     */
    #[Route('/downloads/', name: 'track_download_batch', methods: ['POST'])]
    public function trackDownloadsAction(Request $request): Response
    {
        $contents = \json_decode($request->getContent(), true);
        if (empty($contents['downloads']) || !is_array($contents['downloads'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'], 200);
        }

        $failed = [];
        $ip = $request->getClientIp();

        $jobs = [];
        foreach ($contents['downloads'] as $package) {
            $result = $this->getPackageAndVersionId($package['name'], $package['version']);

            if (!$result) {
                $failed[] = $package;
                continue;
            }

            $jobs[] = ['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $ip];
        }
        $this->downloadManager->addDownloads($jobs);

        if ($failed) {
            return new JsonResponse(['status' => 'partial', 'message' => 'Packages '.json_encode($failed).' not found'], 200);
        }

        return new JsonResponse(['status' => 'success'], 201);
    }


    #[Route('/api/webhook-invoke/{name}', name: 'generic_webhook_invoke', defaults: ['name' => 'default'], methods: ['POST'])]
    public function notifyWebhookAction($name, Request $request): Response
    {
        $payload = \array_merge($request->request->all(), $request->query->all());
        unset($payload['token']);

        $payload = $payload + ($this->getJsonPayload($request) ?: []);

        $context = [
            'event' => Webhook::HOOK_HTTP_REQUEST,
            'name' => $name,
            'ip_address' => $request->getClientIp(),
            'request' => $payload
        ];

        $jobs = [];
        $bus = $this->container->get(HookBus::class);
        $webhooks = $this->registry->getRepository(Webhook::class)
            ->findActive($name,  [Webhook::HOOK_HTTP_REQUEST]);

        foreach ($webhooks as $webhook) {
            $jobs[] = $bus->dispatch($context, $webhook)->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], \count($jobs) === 0 ? 200 : 202);
    }

    /**
     * @param string $name
     * @param string $version
     * @return array
     */
    protected function getPackageAndVersionId($name, $version)
    {
        return $this->getEM()->getConnection()->fetchAssociative(
            'SELECT p.id, v.id as vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.normalizedVersion = ?
            LIMIT 1',
            [$name, $version]
        );
    }

    protected function getJsonPayload(Request $request): ?array
    {
        $payload = $request->request->get('payload') ? \json_decode($request->request->get('payload'), true) : null;
        if (!$payload and $content = $request->getContent()) {
            $payload = @\json_decode($content, true);
        }

        return \is_array($payload) ? $payload : null;
    }

    /**
     * Perform the package update
     *
     * @param Request $request the current request
     * @param string $url the repository's URL (deducted from the request)
     * @param string $urlRegex the regex used to split the user packages into domain and path
     * @return Response
     */
    protected function receivePost(Request $request, $url, $urlRegex)
    {
        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!\preg_match($urlRegex, $url)) {
            return new Response(\json_encode(['status' => 'error', 'message' => 'Could not parse payload repository URL']), 406);
        }

        // try to find the all package
        $packages = $this->findPackagesByUrl($url, $urlRegex);

        return $this->schedulePostJobs($packages);
    }

    /**
     * @param Package[] $packages
     * @return Response
     */
    protected function schedulePostJobs(array $packages)
    {
        if (!$packages) {
            return new Response(\json_encode(['status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)']), 404);
        }

        $jobs = [];

        foreach ($packages as $package) {
            $package->setAutoUpdated(true);
            $this->getEM()->flush($package);

            $job = $this->container->get(Scheduler::class)->scheduleUpdate($package);
            $jobs[] = $job->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], 202);
    }

    /**
     * Find a user package given by its full URL
     *
     * @param string $url
     * @param string $urlRegex
     * @return array the packages found
     */
    protected function findPackagesByUrl($url, $urlRegex)
    {
        if (!\preg_match($urlRegex, $url, $matched)) {
            return [];
        }

        $packages = [];
        $repo = $this->registry->getRepository(Package::class);
        foreach ($repo->findAll() as $package) {
            if (\preg_match($urlRegex, $package->getRepository(), $candidate)
                && \strtolower($candidate['host']) === \strtolower($matched['host'])
                && \strtolower($candidate['path']) === \strtolower($matched['path'])
            ) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedServices(): array
    {
        return \array_merge(
            parent::getSubscribedServices(),
            [
                PackageManager::class,
                Scheduler::class,
                HookBus::class,
            ]
        );
    }
}
