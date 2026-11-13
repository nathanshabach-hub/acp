<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ResultPackageAccess;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

final class ResultPackageAccessTest extends TestCase
{
    public function testSchoolOwnershipRequiresMatchingActiveSchoolSession(): void
    {
        $this->assertTrue(ResultPackageAccess::schoolOwnsRegistration(41, 'School', 41, 41));
        $this->assertFalse(ResultPackageAccess::schoolOwnsRegistration(41, 'School', 0, 41));
        $this->assertFalse(ResultPackageAccess::schoolOwnsRegistration(41, 'School', 41, 42));
        $this->assertFalse(ResultPackageAccess::schoolOwnsRegistration(41, 'Teacher_Parent', 41, 41));
        $this->assertFalse(ResultPackageAccess::schoolOwnsRegistration(0, 'School', 0, 0));
    }

    public function testOnlyConsistentRegistrationsWithExplicitReleaseArePublished(): void
    {
        $this->assertTrue(ResultPackageAccess::registrationIsPublished($this->registration(1)));
        foreach ([0, '0', null, 2, 'published'] as $flag) {
            $this->assertFalse(ResultPackageAccess::registrationIsPublished($this->registration($flag)));
        }

        $seasonWithoutFlag = new Entity(['id' => 7, 'convention_id' => 3, 'season_id' => 2, 'season_year' => 2026]);
        $this->assertFalse(ResultPackageAccess::registrationIsPublished(new Entity([
            'id' => 88, 'user_id' => 41, 'conventionseason_id' => 7, 'convention_id' => 3,
            'season_id' => 2, 'season_year' => 2026, 'Conventions' => new Entity(['id' => 3]),
            'Conventionseasons' => $seasonWithoutFlag,
        ])));

        $this->assertFalse(ResultPackageAccess::registrationIsPublished(new Entity([
            'id' => 88, 'user_id' => 41, 'conventionseason_id' => 7, 'convention_id' => 3,
            'season_id' => 2, 'season_year' => 2026, 'Conventions' => new Entity(['id' => 3]),
            'Conventionseasons' => null,
        ])));
    }

    public function testMismatchedRegistrationAndSeasonRelationshipsAreRejected(): void
    {
        $registration = $this->registration(1);
        $registration->set('conventionseason_id', 8);
        $this->assertFalse(ResultPackageAccess::registrationIsPublished($registration));

        $registration = $this->registration(1);
        $registration->set('season_year', 2025);
        $this->assertFalse(ResultPackageAccess::registrationIsPublished($registration));

        $registration = $this->registration(1);
        $registration->set('convention_id', 9);
        $this->assertFalse(ResultPackageAccess::registrationIsPublished($registration));
    }

    private function registration(mixed $releaseFlag): Entity
    {
        return new Entity([
            'id' => 88,
            'user_id' => 41,
            'conventionseason_id' => 7,
            'convention_id' => 3,
            'season_id' => 2,
            'season_year' => 2026,
            'Conventions' => new Entity(['id' => 3]),
            'Conventionseasons' => new Entity([
                'id' => 7,
                'results_release' => $releaseFlag,
                'convention_id' => 3,
                'season_id' => 2,
                'season_year' => 2026,
            ]),
        ]);
    }
}
