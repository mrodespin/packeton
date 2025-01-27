<?php

namespace Packeton\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Persistence\ObjectRepository;
use Packeton\Repository\VersionRepository;

/**
 * @ORM\Entity(repositoryClass="Packeton\Repository\PackageRepository")
 * @ORM\Table(
 *     name="package",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="package_name_idx", columns={"name"})},
 *     indexes={
 *         @ORM\Index(name="indexed_idx",columns={"indexedAt"}),
 *         @ORM\Index(name="crawled_idx",columns={"crawledAt"}),
 *         @ORM\Index(name="dumped_idx",columns={"dumpedAt"})
 *     }
 * )
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Package
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Unique package name
     *
     * @ORM\Column(length=191)
     */
    private $name;

    /**
     * @ORM\Column(nullable=true)
     */
    private $type;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $description;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $language;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $readme;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_stars")
     */
    private $gitHubStars;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_watches")
     */
    private $gitHubWatches;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_forks")
     */
    private $gitHubForks;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_open_issues")
     */
    private $gitHubOpenIssues;

    /**
     * @ORM\OneToMany(targetEntity="Packeton\Entity\Version", mappedBy="package")
     */
    private $versions;

    /**
     * @ORM\ManyToMany(targetEntity="User", inversedBy="packages")
     * @ORM\JoinTable(name="maintainers_packages")
     */
    private $maintainers;

    /**
     * @ORM\Column()
     */
    private $repository;

    // dist-tags / rel or runtime?

    /**
     * @ORM\Column(type="datetime", name="createdat")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="updatedat")
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="crawledat")
     */
    private $crawledAt;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="indexedat")
     */
    private $indexedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="dumpedat")
     */
    private $dumpedAt;

    /**
     * @ORM\Column(type="boolean", name="autoupdated")
     */
    private $autoUpdated = false;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     */
    private $abandoned = false;

    /**
     * @var string
     * @ORM\Column(type="string", length=255, nullable=true, name="replacementpackage")
     */
    private $replacementPackage;

    /**
     * @ORM\Column(type="boolean", options={"default"=false}, name="updatefailurenotified")
     */
    private $updateFailureNotified = false;

    /**
     * @var SshCredentials
     *
     * @ORM\ManyToOne(targetEntity="SshCredentials")
     * @ORM\JoinColumn(name="credentials_id", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    private $credentials;

    /**
     * @internal
     * @var \Composer\Repository\Vcs\VcsDriverInterface
     */
    public $vcsDriver = true;

    /**
     * @internal
     */
    public $vcsDriverError;

    /**
     * @var array lookup table for versions
     */
    private $cachedVersions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    /**
     * @param VersionRepository|ObjectRepository $versionRepo
     * @return array
     */
    public function toArray(VersionRepository $versionRepo)
    {
        $versions = $versionIds = [];
        $this->versions = $versionRepo->refreshVersions($this->getVersions());
        foreach ($this->getVersions() as $version) {
            $versionIds[] = $version->getId();
        }
        $versionData = $versionRepo->getVersionData($versionIds);
        foreach ($this->getVersions() as $version) {
            /** @var $version Version */
            $versions[$version->getVersion()] = $version->toArray($versionData);
        }
        $maintainers = [];
        foreach ($this->getMaintainers() as $maintainer) {
            /** @var $maintainer User */
            $maintainers[] = $maintainer->toArray();
        }
        $data = [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'time' => $this->getCreatedAt()->format('c'),
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
            'repository' => $this->getRepository(),
            'github_stars' => $this->getGitHubStars(),
            'github_watchers' => $this->getGitHubWatches(),
            'github_forks' => $this->getGitHubForks(),
            'github_open_issues' => $this->getGitHubOpenIssues(),
            'language' => $this->getLanguage(),
        ];

        if ($this->isAbandoned()) {
            $data['abandoned'] = $this->getReplacementPackage() ?: true;
        }

        return $data;
    }

    /**
     * Get id
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get vendor prefix
     *
     * @return string
     */
    public function getVendor()
    {
        return preg_replace('{/.*$}', '', $this->name);
    }

    /**
     * Get package name without vendor
     *
     * @return string
     */
    public function getPackageName()
    {
        return preg_replace('{^[^/]*/}', '', $this->name);
    }

    /**
     * Set description
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set language
     *
     * @param string $language
     */
    public function setLanguage($language)
    {
        $this->language = $language;
    }

    /**
     * Get language
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Set readme
     *
     * @param string $readme
     */
    public function setReadme($readme)
    {
        $this->readme = $readme;
    }

    /**
     * Get readme
     *
     * @return string
     */
    public function getReadme()
    {
        return $this->readme;
    }

    /**
     * @param int $val
     */
    public function setGitHubStars($val)
    {
        $this->gitHubStars = $val;
    }

    /**
     * @return int
     */
    public function getGitHubStars()
    {
        return $this->gitHubStars;
    }

    /**
     * @param int $val
     */
    public function setGitHubWatches($val)
    {
        $this->gitHubWatches = $val;
    }

    /**
     * @return int
     */
    public function getGitHubWatches()
    {
        return $this->gitHubWatches;
    }

    /**
     * @param int $val
     */
    public function setGitHubForks($val)
    {
        $this->gitHubForks = $val;
    }

    /**
     * @return int
     */
    public function getGitHubForks()
    {
        return $this->gitHubForks;
    }

    /**
     * @param int $val
     */
    public function setGitHubOpenIssues($val)
    {
        $this->gitHubOpenIssues = $val;
    }

    /**
     * @return int
     */
    public function getGitHubOpenIssues()
    {
        return $this->gitHubOpenIssues;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set repository
     *
     * @param string $repoUrl
     */
    public function setRepository($repoUrl)
    {
        // prevent local filesystem URLs
        if (preg_match('{^(\.|[a-z]:|/)}i', $repoUrl)) {
            return;
        }

        // normalize protocol case
        $repoUrl = preg_replace_callback('{^(https?|git|svn)://}i', function ($match) { return strtolower($match[1]) . '://'; }, $repoUrl);
        if ($this->repository !== $repoUrl) {
            $this->repository = $repoUrl;
            $this->vcsDriver = $this->vcsDriverError = null;
        }
    }

    /**
     * Get repository
     *
     * @return string $repository
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Get a user-browsable version of the repository URL
     *
     * @return string $repository
     */
    public function getBrowsableRepository()
    {
        if (preg_match('{(://|@)bitbucket.org[:/]}i', $this->repository)) {
            return preg_replace('{^(?:git@|https://|git://)bitbucket.org[:/](.+?)(?:\.git)?$}i', 'https://bitbucket.org/$1', $this->repository);
        }

        if (preg_match('{^(git://github.com/|git@github.com:)}', $this->repository)) {
            return preg_replace('{^(git://github.com/|git@github.com:)}', 'https://github.com/', $this->repository);
        }

        if (preg_match('{^((git|ssh)@(.+))}', $this->repository, $match) && isset($match[3])) {
            return 'https://' . str_replace(':', '/', $match[3]);
        }

        return $this->repository;
    }

    /**
     * Add versions
     *
     * @param Version $versions
     */
    public function addVersions(Version $versions)
    {
        $this->versions[] = $versions;
    }

    /**
     * Get versions
     *
     * @return \Doctrine\Common\Collections\Collection|Version[]
     */
    public function getVersions()
    {
        return $this->versions;
    }

    public function getVersion($normalizedVersion)
    {
        if (null === $this->cachedVersions) {
            $this->cachedVersions = [];
            foreach ($this->getVersions() as $version) {
                $this->cachedVersions[strtolower($version->getNormalizedVersion())] = $version;
            }
        }

        if (isset($this->cachedVersions[strtolower($normalizedVersion)])) {
            return $this->cachedVersions[strtolower($normalizedVersion)];
        }
    }

    /**
     * @return Version|null
     */
    public function getHighest()
    {
        return $this->getVersion('9999999-dev');
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
        $this->setUpdateFailureNotified(false);
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Set crawledAt
     *
     * @param \DateTime|null $crawledAt
     */
    public function setCrawledAt($crawledAt)
    {
        $this->crawledAt = $crawledAt;
    }

    /**
     * Get crawledAt
     *
     * @return \DateTime
     */
    public function getCrawledAt()
    {
        return $this->crawledAt;
    }

    /**
     * Set indexedAt
     *
     * @param \DateTime|null $indexedAt
     */
    public function setIndexedAt($indexedAt)
    {
        $this->indexedAt = $indexedAt;
    }

    /**
     * Get indexedAt
     *
     * @return \DateTime
     */
    public function getIndexedAt()
    {
        return $this->indexedAt;
    }

    /**
     * Set dumpedAt
     *
     * @param \DateTime $dumpedAt
     */
    public function setDumpedAt($dumpedAt)
    {
        $this->dumpedAt = $dumpedAt;
    }

    /**
     * Get dumpedAt
     *
     * @return \DateTime
     */
    public function getDumpedAt()
    {
        return $this->dumpedAt;
    }

    /**
     * Add maintainers
     *
     * @param User|object $maintainer
     */
    public function addMaintainer(User $maintainer)
    {
        $this->maintainers[] = $maintainer;
    }

    /**
     * Get maintainers
     *
     * @return \Doctrine\Common\Collections\Collection|User[]
     */
    public function getMaintainers()
    {
        return $this->maintainers;
    }

    /**
     * Set type
     *
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set autoUpdated
     *
     * @param Boolean $autoUpdated
     */
    public function setAutoUpdated($autoUpdated)
    {
        $this->autoUpdated = $autoUpdated;
    }

    /**
     * Get autoUpdated
     *
     * @return Boolean
     */
    public function isAutoUpdated()
    {
        return $this->autoUpdated;
    }

    /**
     * Set updateFailureNotified
     *
     * @param Boolean $updateFailureNotified
     */
    public function setUpdateFailureNotified($updateFailureNotified)
    {
        $this->updateFailureNotified = $updateFailureNotified;
    }

    /**
     * Get updateFailureNotified
     *
     * @return Boolean
     */
    public function isUpdateFailureNotified()
    {
        return $this->updateFailureNotified;
    }

    /**
     * @return boolean
     */
    public function isAbandoned()
    {
        return $this->abandoned;
    }

    /**
     * @param boolean $abandoned
     */
    public function setAbandoned($abandoned)
    {
        $this->abandoned = $abandoned;
    }

    /**
     * @return string
     */
    public function getReplacementPackage()
    {
        return $this->replacementPackage;
    }

    /**
     * @param string $replacementPackage
     */
    public function setReplacementPackage($replacementPackage)
    {
        $this->replacementPackage = $replacementPackage;
    }

    /**
     * @return SshCredentials|null
     */
    public function getCredentials()
    {
        return $this->credentials;
    }

    /**
     * @param SshCredentials $credentials
     * @return Package
     */
    public function setCredentials(SshCredentials $credentials = null)
    {
        $this->credentials = $credentials;
        return $this;
    }

    public static function sortVersions(Version $a, Version $b)
    {
        $aVersion = $a->getNormalizedVersion();
        $bVersion = $b->getNormalizedVersion();
        $aVersion = preg_replace('{^dev-.*}', '0.0.0-alpha', $aVersion);
        $bVersion = preg_replace('{^dev-.*}', '0.0.0-alpha', $bVersion);

        // equal versions are sorted by date
        if ($aVersion === $bVersion) {
            return $b->getReleasedAt() > $a->getReleasedAt() ? 1 : -1;
        }

        // the rest is sorted by version
        return version_compare($bVersion, $aVersion);
    }
}
