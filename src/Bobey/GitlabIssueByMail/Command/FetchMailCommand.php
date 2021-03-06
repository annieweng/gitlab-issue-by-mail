<?php

namespace Bobey\GitlabIssueByMail\Command;

use Bobey\GitlabIssueByMail\Configuration\ParametersConfiguration;
use Fetch\Message;
use Fetch\Server;
use Gitlab\Client as GitlabClient;
use Gitlab\Model\Project as GitlabProject;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class FetchMailCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('gitlab:fetch-mail')
            ->setDescription('Fetch emails from given mail address and create Gitlab Issues from it');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $yaml = new Parser();

        $config = $yaml->parse(file_get_contents( __DIR__ . '/../../../../config/parameters.yml'));

        $processor = new Processor();
        $configuration = new ParametersConfiguration();
        $processedConfiguration = $processor->processConfiguration($configuration, [$config]);

        // Gitlab parameters
        $token = $processedConfiguration['gitlab']['token'];
        $projectId = $processedConfiguration['gitlab']['projectId'];
        $gitlabUrl = $processedConfiguration['gitlab']['host'];

        // Mail parameters
        $server = $processedConfiguration['mail']['server'];
        $port = $processedConfiguration['mail']['port'];
        $type = $processedConfiguration['mail']['type'];
        $username = $processedConfiguration['mail']['username'];
        $password = $processedConfiguration['mail']['password'];

        $server = new Server($server, $port, $type);
        $server->setAuthentication($username, $password);

        $client = new GitlabClient(sprintf('%s/api/v3/', $gitlabUrl));
        $client->authenticate($token, GitlabClient::AUTH_URL_TOKEN);

        $project = new GitlabProject($projectId, $client);

         /** @var Message[] $messages */
        $messages = $server->getMessages();

        foreach ($messages as $message) {

            $issueTitle = $message->getSubject();
            $issueLabel = str_replace("Re: ", "",$issueTitle);
            $currentIssue = $project->getIssueByLabel($issueLabel);
            $issueContent = 'Issue sent from: '.$message->getAddresses('from', true)."\r\n\n".$message->getMessageBody();

            if(count($currentIssue)==0){
              $project->createIssue($issueTitle, [
                  'description' => $issueContent,
                  'labels' => $issueLabel,
              ]);
              if ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
                  $output->writeln(sprintf('<info>Created a new issue: <comment>%s</comment></info>', $issueTitle));
              }
            }
            else {

              $currentIssue->addComment($issueContent);
              if ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
                  $output->writeln(sprintf('<info>Created a new comment under existing issue: <comment>%s</comment></info>', $issueTitle));
              }
            }




            $message->delete();
        }

        $output->writeln(count($messages) ?
            sprintf('<info>Created %d new issue%s</info>', count($messages), count($messages) > 1 ? 's' : '') :
            '<info>No new issue created</info>'
        );

        $server->expunge();
    }
}
