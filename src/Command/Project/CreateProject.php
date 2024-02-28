<?php

namespace Charcoal\Conductor\Command\Project;

use Charcoal\Conductor\Traits\ModelAwareTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Charcoal\Conductor\Command\AbstractCommand;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

class CreateProject extends AbstractCommand
{
    use ModelAwareTrait;

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('project:create')
            ->setDescription('Create a new charcoal project.')
            ->addArgument('name', InputArgument::REQUIRED, 'Your project\'s name')
            ->addArgument('directory', InputArgument::OPTIONAL, 'The directory of your project')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command creates a new Charcoal project
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $php_exe = $this->getPhpBinaryForCharcoal($output);
        $success = true;

        $this->project_dir = $input->getArgument('directory');
        if (empty($this->project_dir)) {
            $this->project_dir = './' . $input->getArgument('name');
        }

        $command = $php_exe . ' /usr/local/bin/composer create-project charcoal/boilerplate ' . $this->getProjectDir();
        $output->writeln($command);
        $this->runScript($command, $output, true, false);

        try {
            $this->setupDatabase($input, $output);
            $this->copyAdminAssets($input, $output);
        } catch (\Throwable $th) {
            $this->writeError($th->getMessage(), $output);
            $success = false;
        }

        return $success ? self::$SUCCESS : self::$FAILURE;
    }

    private function setupDatabase(InputInterface $input, OutputInterface $output)
    {
        $filesystem = new Filesystem();
        $questionHelper = $this->getQuestionHelper();

        $question = new ConfirmationQuestion('Would you like to configure your database? (Y/n) ');
        $proceed = $questionHelper->ask($input, $output, $question);

        if ($proceed) {
            $output->writeln('Configuring your database...');

            // Hostname.
            $question = new Question('Hostname (127.0.0.1): ');
            $hostname = $questionHelper->ask($input, $output, $question);

            // Database.
            $question = (new Question('Database Name: '))->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException(
                        'The database name must not be empty'
                    );
                }
                return $answer;
            });
            $database = $questionHelper->ask($input, $output, $question);

            // username.
            $question = new Question('Username (root): ');
            $username = $questionHelper->ask($input, $output, $question);

            // password.
            $question = (new Question('Password (empty): '))
                ->setHidden(true)
                ->setHiddenFallback(false);
            $password = $questionHelper->ask($input, $output, $question);

            // Get current config file
            $configSample = file_get_contents($this->getProjectDir() . '/config/config.sample.json');
            $configSample = json_decode($configSample, true);

            if (!$configSample) {
                throw new Exception('Failed to read your project\'s config.sample.json');
            }

            $configSample = array_merge($configSample, [
                'databases' => [
                    'mysql' => [
                        'type' => 'mysql',
                        'hostname' => !empty($hostname) ? $hostname : '127.0.0.1',
                        'database' => !empty($database) ? $database : '',
                        'username' => !empty($username) ? $username : 'root',
                        'password' => !empty($password) ? $password : '',
                    ]
                ]
            ]);

            $configDirectory = $this->getProjectDir() . '/config';
            if (!$filesystem->exists($configDirectory . '/config.local.json')) {
                if (!$filesystem->exists($configDirectory)) {
                    $filesystem->mkdir($configDirectory);
                }
                $prettyJson = json_encode($configSample, JSON_PRETTY_PRINT);
                $filesystem->dumpFile($configDirectory . '/config.local.json', $prettyJson);
            }
        }
    }

    private function copyAdminAssets(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();

        $question = new ConfirmationQuestion('Would you like compile the admin assets? (Y/n) ');
        $proceed = $questionHelper->ask($input, $output, $question);

        if ($proceed) {
            $output->writeln('Compiling the admin assets...');
        }
    }

    private function getQuestionHelper(): QuestionHelper
    {
        return $this->getHelper('question');
    }
}
