<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

declare(strict_types=1);

namespace RoaveTest\SecurityAdvisories;

use PHPUnit_Framework_TestCase;
use Roave\SecurityAdvisories\Advisory;
use Roave\SecurityAdvisories\VersionConstraint;

/**
 * Tests for {@see \Roave\SecurityAdvisories\Advisory}
 *
 * @covers \Roave\SecurityAdvisories\Advisory
 */
final class AdvisoryTest extends PHPUnit_Framework_TestCase
{
    public function testFromArrayWithValidConfig() : void
    {
        $advisory = Advisory::fromArrayData([
            'reference' => 'composer://foo/bar',
            'branches' => [
                '1.0.x' => [
                    'versions' => ['>=1.0', '<1.1'],
                ],
                '2.0.x' => [
                    'versions' => ['>=2.0', '<2.1'],
                ],
            ],
        ]);

        self::assertInstanceOf(Advisory::class, $advisory);

        self::assertSame('foo/bar', $advisory->getComponentName());
        self::assertSame('>=1,<1.1|>=2,<2.1', $advisory->getConstraint());

        $constraints = $advisory->getVersionConstraints();

        self::assertCount(2, $constraints);
        self::assertInstanceOf(VersionConstraint::class, $constraints[0]);
        self::assertInstanceOf(VersionConstraint::class, $constraints[1]);

        self::assertSame('>=1,<1.1', $constraints[0]->getConstraintString());
        self::assertSame('>=2,<2.1', $constraints[1]->getConstraintString());
    }

    /**
     * @dataProvider unsortedBranchesProvider
     */
    public function testFromArrayGeneratesSortedResult(array $versionConstraint1, array $versionConstraint2, string $expected) : void
    {
        $advisory = Advisory::fromArrayData([
            'reference' => 'composer://foo/bar',
            'branches' => [
                '2.0.x' => [
                    'versions' => $versionConstraint2,
                ],
                '1.0.x' => [
                    'versions' => $versionConstraint1,
                ],
            ],
        ]);

        self::assertSame($expected, $advisory->getConstraint());
    }

    public function unsortedBranchesProvider()
    {
        return [
            [
                ['>=1.0', '<1.1'],
                ['>=2.0', '<2.1'],
                '>=1,<1.1|>=2,<2.1',
            ],
            [
                ['>=1.0', '<1.1'],
                ['>=2.0'],
                '>=1,<1.1|>=2',
            ],
            [
                ['<1.1'],
                ['>=2.0', '<2.1'],
                '<1.1|>=2,<2.1',
            ],
        ];
    }
}
