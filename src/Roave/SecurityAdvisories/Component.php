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

namespace Roave\SecurityAdvisories;

final class Component
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var Advisory[]
     */
    private $advisories;

    /**
     * @param string     $name
     * @param Advisory[] $advisories
     */
    public function __construct(string $name, array $advisories)
    {
        static $checkAdvisories;

        $checkAdvisories = $checkAdvisories ?: function (Advisory ...$advisories) {
            return $advisories;
        };

        $this->name       = (string) $name;
        $this->advisories = $checkAdvisories(...array_values($advisories));
    }

    public function getName() : string
    {
        return $this->name;
    }

    /**
     * @return string
     *
     * @throws \LogicException
     */
    public function getConflictConstraint() : string
    {
        return implode(
            '|',
            array_filter(array_map(
                function (VersionConstraint $versionConstraint) {
                    return $versionConstraint->getConstraintString();
                },
                $this->deDuplicateConstraints(array_merge(
                    [],
                    ...array_values(array_map(
                        function (Advisory $advisory) {
                            return $advisory->getVersionConstraints();
                        },
                        $this->advisories
                    ))
                ))
            ))
        );
    }

    /**
     * @param VersionConstraint[] $constraints
     *
     * @return VersionConstraint[]
     *
     * @throws \LogicException
     */
    private function deDuplicateConstraints(array $constraints) : array
    {
        restart:

        foreach ($constraints as & $constraint) {
            foreach ($constraints as $key => $comparedConstraint) {
                if ($constraint !== $comparedConstraint && $constraint->canMergeWith($comparedConstraint)) {
                    unset($constraints[$key]);
                    $constraint = $constraint->mergeWith($comparedConstraint);

                    // note: this is just simulating tail recursion. Normal recursion not viable here, and `foreach`
                    //       becomes unstable when elements are removed from the loop
                    goto restart;
                }
            }
        }

        usort($constraints, new VersionConstraintSort());

        return $constraints;
    }
}
