<?php

declare(strict_types=1);


namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Models\Company;
use App\Support\Appointments\AppointmentSerializer;
use App\Support\PhoneNumberNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAppointmentsAction
{
    public function __construct(private readonly AppointmentSerializer $serializer) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function handle(Company $company, array $validated): array
    {
        $from = isset($validated['date_from'])
            ? CarbonImmutable::parse((string) $validated['date_from'])->startOfDay()
            : now()->startOfDay();
        $to = isset($validated['date_to'])
            ? CarbonImmutable::parse((string) $validated['date_to'])->endOfDay()
            : now()->addDays(14)->endOfDay();
        $perPage = (int) ($validated['per_page'] ?? 50);

        $phoneVariants = [];
        if (! empty($validated['customer_phone'])) {
            $phoneVariants = PhoneNumberNormalizer::variantsForLookup((string) $validated['customer_phone']);
        }

        $query = Appointment::query()
            ->where('company_id', (int) $company->id)
            ->where('starts_at', '>=', $from->setTimezone('UTC'))
            ->where('starts_at', '<=', $to->setTimezone('UTC'))
            ->with(['service:id,name', 'staffProfile.user:id,name,email'])
            ->orderBy('starts_at');

        if (! empty($validated['staff_profile_id'])) {
            $query->where('staff_profile_id', (int) $validated['staff_profile_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if ($phoneVariants !== []) {
            $query->whereIn('customer_phone', $phoneVariants);
        }

        /** @var LengthAwarePaginator $rows */
        $rows = $query->paginate($perPage);

        return [
            'items' => collect($rows->items())
                ->map(fn(Appointment $appointment) => $this->serializer->serializeAppointment($appointment))
                ->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page'    => $rows->lastPage(),
                'per_page'     => $rows->perPage(),
                'total'        => $rows->total(),
            ],
        ];
    }
}
