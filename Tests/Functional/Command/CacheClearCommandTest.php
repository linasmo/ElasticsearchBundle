<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Tests\Functional\Command;

use ONGR\ElasticsearchBundle\Command\CacheClearCommand;
use ONGR\ElasticsearchBundle\Test\ElasticsearchTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CacheClearCommandTest extends ElasticsearchTestCase
{
    /**
     * Tests if command is being executed.
     */
    public function testExecute()
    {
        $app = new Application();
        $app->add($this->getCommand());
        $command = $app->find('es:cache:clear');
        $tester = new CommandTester($command);
        $tester->execute(
            [
                'command' => $command->getName(),
            ]
        );

        $this->assertContains('Elasticsearch cache has been cleared for default index.', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode(), 'Status code should be zero.');
    }

    /**
     * Tests if exception is thown when no connection is found.
     *
     * @expectedException \RuntimeException
     */
    public function testExecuteException()
    {
        $app = new Application();
        $app->add($this->getCommand());
        $command = $app->find('es:cache:clear');
        $tester = new CommandTester($command);
        $tester->execute(
            [
                'command' => $command->getName(),
                '--connection' => 'foo',
            ]
        );
    }

    /**
     * Returns cache clear command instance.
     *
     * @return CacheClearCommand
     */
    private function getCommand()
    {
        $command = new CacheClearCommand();
        $command->setContainer($this->getContainer());

        return $command;
    }
}
