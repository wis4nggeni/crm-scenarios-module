<?php

namespace Crm\ScenariosModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;
use Nette\Database\Table\Selection;
use Nette\Utils\DateTime;

class ElementsRepository extends Repository
{
    protected $tableName = 'scenarios_elements';

    const ELEMENT_TYPE_EMAIL = 'email';
    const ELEMENT_TYPE_GOAL = 'goal';
    const ELEMENT_TYPE_SEGMENT = 'segment';
    const ELEMENT_TYPE_CONDITION = 'condition';
    const ELEMENT_TYPE_WAIT = 'wait';
    const ELEMENT_TYPE_BANNER = 'banner';

    public function all()
    {
        return $this->scopeNotDeleted();
    }

    public function findByUuid($uuid)
    {

        return $this->scopeNotDeleted()->where(['uuid' => $uuid])->fetch();
    }

    public function removeAllByScenarioID(int $scenarioId)
    {
        foreach ($this->allScenarioElements($scenarioId) as $element) {
            $this->delete($element);
        }
    }

    public function allScenarioElements(int $scenarioId): Selection
    {
        return $this->scopeNotDeleted()->where([
            'scenario_id' => $scenarioId
        ]);
    }

    public function findByScenarioIDAndElementUUID(int $scenarioId, string $elementUuid)
    {
        return $this->allScenarioElements($scenarioId)
            ->where(['uuid' => $elementUuid])
            ->fetch();
    }

    public function delete(IRow &$row)
    {
        // Soft-delete
        $this->update($row, ['deleted_at' => new DateTime()]);
    }

    public function deleteByUuids(array $uuids)
    {
        $elements = $this->scopeNotDeleted()->where('uuid IN (?)', $uuids)->fetchAll();
        foreach ($elements as $element) {
            $this->delete($element);
        }
    }

    private function scopeNotDeleted()
    {
        return $this->getTable()->where('deleted_at IS NULL');
    }
}
