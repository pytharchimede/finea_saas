<?php
declare(strict_types=1);

final class PlanningBlockageController
{
    public function __construct(private PlanningBlockageRepository $blockages) {}

    public function save(array $data): int
    {
        if (trim((string)($data['cause'] ?? '')) === '') {
            throw new InvalidArgumentException('La cause du blocage est obligatoire.');
        }
        return $this->blockages->save($data);
    }

    public function close(int $id): void
    {
        $this->blockages->close($id);
    }
}
