<?php


namespace Elendev\NexusComposerPush;

use Composer\Command\BaseCommand;
use Composer\Config;
use Composer\IO\IOInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

class PushCommand extends BaseCommand
{

    /**
     * @var \GuzzleHttp\ClientInterface
     */
    private $client;

    /**
     * @var string
     */
    private $projectVendorDir;

    /**
     * @var string
     */
    private $globalVendorDir;

    protected function configure()
    {
        $this
            ->setName('nexus-push')
            ->setDescription('Initiate a push to a distant Nexus repository')
            ->setDefinition([
                new InputArgument('version', InputArgument::REQUIRED, 'The package version'),
                new InputOption('name', null, InputArgument::OPTIONAL, 'Name of the package (if different from the composer.json file)'),
                new InputOption('url', null, InputArgument::OPTIONAL, 'URL to the distant Nexus repository'),
                new InputOption(
                    'username',
                    null,
                    InputArgument::OPTIONAL,
                    'Username to log in the distant Nexus repository'
                ),
                new InputOption('password', null, InputArgument::OPTIONAL, 'Password to log in the distant Nexus repository'),
                new InputOption('ignore', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Directories and files to ignore when creating the zip'),
                new InputOption('ignore-dirs', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, '<error>DEPRECATED</error> Directories to ignore when creating the zip'),
                new InputOption('ignore-by-git-attributes', null, InputOption::VALUE_NONE, 'Ignore .gitattrbutes export-ignore directories when creating the zip'),
                new InputOption('ignore-by-composer', null, InputOption::VALUE_NONE, 'Ignore composer.json archive-exclude files and directories when creating the zip'),
            ])
            ->setHelp(
                <<<EOT
The <info>nexus-push</info> command uses the archive command to create a ZIP
archive and send it to the configured (or given) nexus repository.
EOT
            )
        ;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|null|void
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fileName = tempnam(sys_get_temp_dir(), 'nexus-push') . '.zip';

        $packageName = $this->getPackageName($input);

        $subdirectory = strtolower(preg_replace(
            '/[^a-zA-Z0-9_]|\./',
            '-',
            $packageName . '-' . $input->getArgument('version')
        ));

        $ignoredDirectories = $this->getIgnores($input);
        $this->getIO()
            ->write(
                'Ignore directories: ' . join(' ', $ignoredDirectories),
                true,
                IOInterface::VERY_VERBOSE
            );

        try {
            ZipArchiver::archiveDirectory(
                getcwd(),
                $fileName,
                $subdirectory,
                $ignoredDirectories,
                $this->getIO()
            );

            $url = $this->generateUrl(
                $input->getOption('url'),
                $packageName,
                $input->getArgument('version')
            );

            $this->getIO()
                ->write(
                    'Execute the Nexus Push for the URL ' . $url . '...',
                    true
                );

            $this->sendFile(
                $url,
                $fileName,
                $input->getOption('username'),
                $input->getOption('password')
            );

            $this->getIO()
                ->write('Archive correctly pushed to the Nexus server');
        } finally {
            $this->getIO()
                ->write(
                    'Remove file ' . $fileName,
                    true,
                    IOInterface::VERY_VERBOSE
                );
            unlink($fileName);
        }
    }

    /**
     * @param string $url
     * @param string $name
     * @param string $version
     *
     * @return string URL to the repository
     */
    private function generateUrl($url, $name, $version)
    {
        if (empty($url)) {
            $url = $this->getNexusExtra('url');

            if (empty($url)) {
                throw new InvalidArgumentException('The option --url is required or has to be provided as an extra argument in composer.json');
            }
        }

        if (empty($name)) {
            $name = $this->getComposer(true)->getPackage()->getName();
        }

        if (empty($version)) {
            throw new InvalidArgumentException('The version argument is required');
        }

        // Remove trailing slash from URL
        $url = preg_replace('{/$}', '', $url);

        return sprintf('%s/packages/upload/%s/%s', $url, $name, $version);
    }

    /**
     * Try to send a file with the given username/password. If the credentials
     * are not set, try to send a simple request without credentials. If the
     * send fail with a 401, try to use the credentials that may be available
     * in an `auth.json` file or in the
     * `extra` section
     *
     * @param string      $url      URL to send the file to
     * @param string      $filePath path to the file to send
     * @param string|null $username
     * @param string|null $password
     *
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function sendFile(
        $url,
        $filePath,
        $username = null,
        $password = null
    ) {
        if (!empty($username) && !empty($password)) {
            $this->postFile($url, $filePath, $username, $password);
            return;
        } else {
            $credentials = [];

            if ($this->getNexusExtra('username') !== null && $this->getNexusExtra('password')) {
                $credentials['extra'] = [
                    'username' => $this->getNexusExtra('username'),
                    'password' => $this->getNexusExtra('password'),
                ];
            }


            if (preg_match(
                    '{^(?:https?)://([^/]+)(?:/.*)?}',
                    $url,
                    $match
                ) && $this->getIO()->hasAuthentication($match[1])) {
                $auth                     = $this->getIO()->getAuthentication($match[1]);
                $credentials['auth.json'] = [
                    'username' => $auth['username'],
                    'password' => $auth['password'],
                ];
            }

            // In the case anything else works, try to connect without any credentials.
            $credentials['none'] = [];

            foreach ($credentials as $type => $credential) {
                $this->getIO()
                    ->write(
                        '[postFile] Trying credentials ' . $type,
                        true,
                        IOInterface::VERY_VERBOSE
                    );

                $options = [
                    'body' => fopen($filePath, 'r'),
                ];

                if (!empty($credential)) {
                    $options['auth'] = $credential;
                }

                try {
                    if (empty($credential) || empty($credential['username']) || empty($credential['password'])) {
                        $this->getIO()
                            ->write(
                                '[postFile] Use no credentials',
                                true,
                                IOInterface::VERY_VERBOSE
                            );
                        $this->postFile($url, $filePath);
                    } else {
                        $this->getIO()
                            ->write(
                                '[postFile] Use user ' . $credential['username'],
                                true,
                                IOInterface::VERY_VERBOSE
                            );
                        $this->postFile(
                            $url,
                            $filePath,
                            $credential['username'],
                            $credential['password']
                        );
                    }

                    return;
                } catch (ClientException $e) {
                    if ($e->getResponse()->getStatusCode() === '401') {
                        if ($type === 'none') {
                            $this->getIO()
                                ->write(
                                    'Unable to push on server (authentication required)',
                                    true,
                                    IOInterface::VERY_VERBOSE
                                );
                        } else {
                            $this->getIO()
                                ->write(
                                    'Unable to authenticate on server with credentials ' . $type,
                                    true,
                                    IOInterface::VERY_VERBOSE
                                );
                        }
                    } else {
                        $this->getIO()
                            ->writeError(
                                'A network error occured while trying to upload to nexus: ' . $e->getMessage(),
                                true,
                                IOInterface::QUIET
                            );
                    }
                }
            }
        }

        throw new \Exception('Impossible to push to remote repository, use -vvv to have more details');
    }

    /**
     * The file has to be uploaded by hand because of composer limitations
     * (impossible to use Guzzle functions.php file in a composer plugin).
     *
     * @param $url
     * @param $file
     * @param $username
     * @param $password
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function postFile($url, $file, $username = null, $password = null)
    {
        $options = [
            'body'  => fopen($file, 'r'),
            'debug' => $this->getIO()->isVeryVerbose(),
        ];

        if (!empty($username) && !empty($password)) {
            $options['auth'] = [$username, $password];
        }

        $this->getClient()->request('PUT', $url, $options);
    }

    /**
     * @throws FileNotFoundException
     * @return \GuzzleHttp\Client|\GuzzleHttp\ClientInterface
     */
    private function getClient()
    {
        if (empty($this->client)) {
            // https://github.com/composer/composer/issues/5998
            $autoload = $this->getVendorFile('/autoload.php');

            // Require the guzzle functions manually.
            $guzzlefunctions         = $this->getVendorFile('/guzzlehttp/guzzle/src/functions_include.php');
            $guzzlepsr7functions     = $this->getVendorFile('/guzzlehttp/psr7/src/functions_include.php');
            $guzzlepromisesfunctions = $this->getVendorFile('/guzzlehttp/promises/src/functions_include.php');
            require $guzzlefunctions;
            require $guzzlepsr7functions;
            require $guzzlepromisesfunctions;
            require $autoload;

            $this->client = new Client();
        }
        return $this->client;
    }

    private function getProjectVendorDir()
    {
        if (!$this->projectVendorDir) {
            $composer  = $this->getComposer(true);
            $vendorDir = $composer->getConfig()->get('vendor-dir');

            // Show an error if the file wasn't found in the current project.
            if (file_exists($vendorDir . '/elendev/nexus-composer-push')) {
                $this->projectVendorDir = $vendorDir;
            }
        }

        return $this->projectVendorDir;
    }

    private function getGlobalVendorDir()
    {
        if (!$this->globalVendorDir) {
            $composer  = $this->getComposer(true);
            $vendorDir = $composer->getConfig()->get('data-dir') . '/' . $composer->getConfig()->get('vendor-dir', Config::RELATIVE_PATHS);

            // Show an error if the file wasn't found in the current project.
            if (file_exists($vendorDir . '/elendev/nexus-composer-push')) {
                $this->globalVendorDir = $vendorDir;
            }
        }

        return $this->globalVendorDir;
    }

    private function getVendorFile($file)
    {
        try {
            $vendorDir  = $this->getProjectVendorDir();
            $vendorFile = $vendorDir . $file;
            if (!file_exists($vendorFile)) {
                throw new FileNotFoundException("$file not found, is guzzle installed?");
            }
        } catch (FileNotFoundException $e) {
            $vendorDir = $this->getGlobalVendorDir();
            $vendorFile = $vendorDir . $file;
            if (!file_exists($vendorFile)) {
                throw new FileNotFoundException("$file not found, is guzzle installed?");
            }
        }

        return $vendorFile;
    }

    /**
     * Return the package name based on the given name or the real package name.
     *
     * @param \Symfony\Component\Console\Input\InputInterface|null $input
     *
     * @return string
     */
    private function getPackageName(InputInterface $input = null)
    {
        if ($input && $input->getOption('name')) {
            return $input->getOption('name');
        } else {
            return $this->getComposer(true)->getPackage()->getName();
        }
    }

    /**
     * Get the Nexus extra values if available
     *
     * @param      $parameter
     * @param null $default
     *
     * @return array|string|null
     */
    private function getNexusExtra($parameter, $default = null)
    {
        $extras = $this->getComposer(true)->getPackage()->getExtra();

        if (!empty($extras['nexus-push'][$parameter])) {
            return $extras['nexus-push'][$parameter];
        } else {
            return $default;
        }
    }

    /**
     * Fetch any directories or files to be excluded from zip creation
     *
     * @param InputInterface $input
     * @return array
     */
    private function getIgnores(InputInterface $input)
    {
        // Remove after removal of --ignore-dirs option
        $deprecatedIgnores = $this->getDirectoriesToIgnore($input);

        $optionalIgnore      = $input->getOption('ignore');
        $composerIgnores     = $this->getNexusExtra('ignore', []);
        $gitAttrIgnores      = $this->getGitAttributesExportIgnores($input);
        $composerJsonIgnores = $this->getComposerJsonArchiveExcludeIgnores($input);
        $defaultIgnores      = ['vendor/'];

        $ignore = array_merge($deprecatedIgnores, $composerIgnores, $optionalIgnore, $gitAttrIgnores, $composerJsonIgnores, $defaultIgnores);
        return array_unique($ignore);
    }

    /**
     * @param InputInterface $input
     * @deprecated argument has been changed to ignore
     * @return array
     */
    private function getDirectoriesToIgnore(InputInterface $input)
    {
        $optionalIgnore  = $input->getOption('ignore-dirs');
        $composerIgnores = $this->getNexusExtra('ignore-dirs', []);

        if (!empty($optionalIgnore)) {
            $this->getIO()->write('<error>The --ignore-dirs option has been deprecated. Please use --ignore instead</error>');
        }

        if (!empty($composerIgnores)) {
            $this->getIO()->write('<error>The ignore-dirs config option has been deprecated. Please use ignore instead</error>');
        }

        $ignore = array_merge($composerIgnores, $optionalIgnore);
        return array_unique($ignore);
    }

    private function getGitAttributesExportIgnores(InputInterface $input)
    {
        $option = $input->getOption('ignore-by-git-attributes');
        $extra  = $this->getNexusExtra('ignore-by-git-attributes', false);
        if (!$option && !$extra) {
            return [];
        }

        $path = getcwd() . '/.gitattributes';
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        $lines    = explode(PHP_EOL, $contents);
        $ignores  = [];
        foreach ($lines as $line) {
            if ($line = trim($line)) {
                // ignore if end with `export-ignore`
                $diff = strlen($line) - 13;
                if ($diff > 0 && strpos($line, 'export-ignore', $diff) !== false) {
                    $ignores[] = trim(trim(explode(' ', $line)[0]), DIRECTORY_SEPARATOR);
                }
            }
        }

        return $ignores;
    }

    private function getComposerJsonArchiveExcludeIgnores(InputInterface $input)
    {
        $option = $input->getOption('ignore-by-composer');
        $extra  = $this->getNexusExtra('ignore-by-composer', false);
        if (!$option && !$extra) {
            return [];
        }

        $path = getcwd() . '/composer.json';

        $contents     = file_get_contents($path);
        $jsonContents = json_decode($contents, true);
        $ignores      = [];
        if (array_key_exists('archive', $jsonContents) && array_key_exists('exclude', $jsonContents['archive'])) {
            foreach ($jsonContents['archive']['exclude'] as $exclude) {
                $ignores[] = trim($exclude, DIRECTORY_SEPARATOR);
            }
        }

        return $ignores;
    }
}
