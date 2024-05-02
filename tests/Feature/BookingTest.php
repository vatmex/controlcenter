<?php

namespace Tests\Feature;

use App\Helpers\VatsimRating;
use App\Models\Booking;
use App\Models\Endorsement;
use App\Models\Position;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private function assertCreateBookingAvailable(User $controller, Position $position)
    {
        $this->actingAs($controller)->followingRedirects()
            ->get(route('booking'))
            ->assertSee($position->name)
            ->assertSeeText('Create Booking');
    }

    private function createBooking(User $controller, Position $position)
    {
        $lastBooking = Booking::all()->last();

        $startDate = new Carbon(fake()->dateTimeBetween('tomorrow', '+2 months'));
        $endDate = $startDate->copy()->addHours(2)->addMinutes(30);
        $bookingRequest = [
            'date' => $startDate->format('d/m/Y'),
            'start_at' => $startDate->format('H:i'),
            'end_at' => $endDate->format('H:i'),
            'position' => $position->callsign,
        ];
        $response = $this->actingAs($controller)->followingRedirects()->post(
            '/booking/store',
            $bookingRequest
        );

        $response->assertSuccessful();
        $this->assertNotSame($lastBooking, Booking::all()->last());

        return $response;
    }

    /**
     * Validate that a controller can create a booking.
     * The data provider underneath is used by PHPUnit fill the arguments of the test.
     *
     * @dataProvider controllerProvider
     */
    public function test_active_ratings_can_create_bookings(VatsimRating $rating, callable $setup): void
    {
        $controller = User::factory()->create([
            'id' => fake()->numberBetween(100),
            'rating' => $rating->value,
        ]);

        $controller->atcActivity()->create([
            'user_id' => $controller->id,
            'area_id' => 1,
            'hours' => 100,
            'atc_active' => true,
        ]);

        $setup($controller);
        $highestTraining = Training::with('ratings')->where('status', 2)->whereBelongsTo($controller)->get()->sortByDesc('vatsim_rating')->first();

        // If there's training available, let's try to create a booking for the training
        if ($highestTraining) {
            $rating = $highestTraining->getHighestVatsimRating()->vatsim_rating;
            $position = Position::with('area')->where('rating', '<=', $rating)
                ->whereBelongsTo($controller->atcActivity->first()->area)
                ->whereNotNull('name')->orderByDesc('rating')->first();
            $this->assertGreaterThan($controller->rating, $rating);
            $this->assertCreateBookingAvailable($controller, $position);
            $this->createBooking($controller, $position)->assertValid()->assertSeeText('training tag');
        }

        $rating = $controller->rating;
        // Select a high position given the status we have
        $position = Position::with('area')->where('rating', '<=', $rating)
            ->whereBelongsTo($controller->atcActivity->first()->area)
            ->whereNotNull('name')->inRandomOrder()->orderByDesc('rating')->first();
        $this->assertCreateBookingAvailable($controller, $position);
        $this->createBooking($controller, $position)->assertValid()->assertDontSeeText('training tag');
    }

    /**
     * Provides a list of controllers to feed to the booking test.
     *
     * TODO: These should use a repository or another factory, 'cause it's painful to use training directly.
     * TODO: Use enums to make this more maintainable rather than use ints directly.
     * TODO: Consider using $training->ratings()->saveMany() instead of factory.
     */
    public static function controllerProvider(): array
    {
        return [
            'S1 Rating with endorsement' => [
                VatsimRating::S1,
                function ($user) {
                    Endorsement::factory()->create(
                        ['user_id' => $user->id, 'type' => 'S1', 'valid_to' => null]
                    );
                },
            ],
            'S1 training for S2' => [
                VatsimRating::S1,
                function ($user) {
                    Training::factory()
                        ->has(Rating::factory(['vatsim_rating' => VatsimRating::S2]))
                        ->create(['user_id' => $user->id, 'type' => 1, 'status' => 2]);
                },
            ],
            'S2 Rating' => [
                VatsimRating::S2,
                function () {
                },
            ],
            'S2 Rating training for S3' => [
                VatsimRating::S2,
                function ($user) {
                    Training::factory()
                        ->has(Rating::factory(['vatsim_rating' => VatsimRating::S3]))
                        ->create(['user_id' => $user->id, 'type' => 1, 'status' => 2]);
                },
            ],
            'S3 Rating' => [
                VatsimRating::S3,
                function () {
                },
            ],
            'S3 Rating training for C1' => [
                VatsimRating::S3,
                function ($user) {
                    Training::factory()
                        ->has(Rating::factory(['vatsim_rating' => VatsimRating::C1]))
                        ->create(['user_id' => $user->id, 'type' => 1, 'status' => 2]);
                },
            ],
            'C1 Rating' => [
                VatsimRating::C1,
                function () {
                },
            ],
            'C3 Rating' => [
                VatsimRating::C3,
                function () {
                },
            ],
            'I1 Rating' => [
                VatsimRating::I1,
                function () {
                },
            ],
            'I3 Rating' => [
                VatsimRating::I3,
                function () {
                },
            ],
        ];
    }

    /**
     * Validate that a booking cannot be created with the same start and end date.
     */
    public function test_cannot_create_booking_with_same_start_and_end_time()
    {
        $controller = User::factory()->create([
            'id' => fake()->numberBetween(100),
            'rating' => VatsimRating::C1->value,
        ]);

        $controller->atcActivity()->create([
            'user_id' => $controller->id,
            'area_id' => 1,
            'hours' => 100,
            'atc_active' => true,
        ]);

        $startDate = new Carbon(fake()->dateTimeBetween('tomorrow', '+2 months'));

        $bookingRequest = [
            'date' => $startDate->format('d/m/Y'),
            'start_at' => $startDate->format('H:i'),
            'end_at' => $startDate->format('H:i'),
            'position' => Position::where('rating', '<=', $controller->rating)
                ->whereBelongsTo($controller->atcActivity->first()->area)
                ->whereNotNull('name')
                ->inRandomOrder()
                ->orderByDesc('rating')
                ->first()
                ->callsign,
        ];

        $response = $this->actingAs($controller)->followingRedirects()->post(
            '/booking/store',
            $bookingRequest
        );

        error_log($response);
        $response->assertInvalid();
        $response->assertSessionHasErrors('end_at');
    }
}
