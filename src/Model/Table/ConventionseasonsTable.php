<?php
namespace App\Model\Table;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ConventionseasonsTable extends Table{
    public function beforeMarshal(\Cake\Event\EventInterface $event, \ArrayObject $data, \ArrayObject $options): void
    {
        if (isset($data['judge_registration_fees']) && $data['judge_registration_fees'] === '') {
            $data['judge_registration_fees'] = null;
        }
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator->allowEmptyString('judge_registration_fees')
            ->add('judge_registration_fees', 'money', [
                'rule' => static fn($value): bool => (is_string($value) || is_int($value))
                    && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string)$value) === 1,
                'message' => 'Enter a non-negative amount with no more than two decimal places.',
            ]);
    }
    
    public function initialize(array $config): void
    {
		$this->belongsTo('Conventions', [
            'className' => 'Conventions',
            'foreignKey' => 'convention_id',
            'propertyName' => 'Conventions'
        ]);
		
		$this->belongsTo('Seasons', [
            'className' => 'Seasons',
            'foreignKey' => 'season_id',
            'propertyName' => 'Seasons'
        ]);
    }

}
?>