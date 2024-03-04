<?php

namespace NW\WebService\References\Operations\Notification;

/** Простая валидация данных
 * Class Validate
 * @package NW\WebService\References\Operations\Notification
 */
class Validate {
    /** Валидация числа
     * @param $value - значение
     * @return bool - прошла ли валидация
     */
    public static function int(&$value): bool {
        if (is_numeric($value) || is_bool($value)) {
            $value = (int)$value;
            return true;
        }
        return false;
    }

    /** Валидация строки
     * @param $value - значение
     * @return bool - прошла ли валидация
     */
    public static function str(&$value): bool {
        if (is_bool($value)) {
            $value = (string)(int)$value;
            return true;
        }
        if (is_string($value)) {
            $value = htmlspecialchars($value);
            return true;
        }
        if (is_null($value)) {
            $value = null;
            return true;
        }
        return false;
    }
}

/** Класс для работы с операциями возврата
 * Class TsReturnOperation
 * @package NW\WebService\References\Operations\Notification
 */
class TsReturnOperation extends ReferencesOperation {
    public const TYPE_NEW = 1; // Тип уведомления о новой позиции
    public const TYPE_CHANGE = 2; // Тип уведомления о смене позиции

    protected function getRequiestData(): array {
        // Получаем данные из запроса
        $data = $this->getRequest('data');
        // Валидация данных
        if (!is_array($data)) {
            throw new \Exception('Invalid data', 400);
        }

        // Валидация всех входных параметров. Я думаю, что в боевом проекте есть отдельная либа или свой класс, который за это отвечает
        if (empty($data['resellerId']) || Validate::int($data['resellerId']))
            throw new \Exception('Empty resellerId', 400);
        if (empty($data['notificationType']) || Validate::int($data['notificationType']))
            throw new \Exception('Empty notificationType', 400);
        if (empty($data['clientId']) || Validate::int($data['clientId']))
            throw new \Exception('Empty clientId', 400);
        if (empty($data['creatorId']) || Validate::int($data['creatorId']))
            throw new \Exception('Empty creatorId', 400);
        if (empty($data['expertId']) || Validate::int($data['expertId']))
            throw new \Exception('Empty expertId', 400);
        if (empty($data['complaintId']) || Validate::int($data['complaintId']))
            throw new \Exception('Empty complaintId', 400);
        if (empty($data['complaintNumber']) || Validate::str($data['complaintNumber']))
            throw new \Exception('Empty complaintNumber', 400);
        if (empty($data['consumptionId']) || Validate::int($data['consumptionId']))
            throw new \Exception('Empty consumptionId', 400);
        if (empty($data['consumptionNumber']) || Validate::str($data['consumptionNumber']))
            throw new \Exception('Empty consumptionNumber', 400);
        if (empty($data['agreementNumber']) || Validate::str($data['agreementNumber']))
            throw new \Exception('Empty agreementNumber', 400);
        if (empty($data['date']) || Validate::str($data['date']))
            throw new \Exception('Empty date', 400);

        // Обработка не обязательного параметра
        if (empty($data['differences']))
            $data['differences'] = null;

        return $data;
    }

    /** Получение реселлера
     * @param int $resellerId - id реселлера
     * @return Seller
     * @throws \Exception
     */
    private function getReseller(int $resellerId): Seller {
        $reseller = Seller::getById($resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }
        return $reseller;
    }

    /** Получение клиента
     * @param int $clientId - id клиента
     * @param Seller|int $reseller - id реселлера или объект реселлера
     * @return Contractor
     * @throws \Exception
     */
    private function getClient(int $clientId, Seller|int $reseller): Contractor {
        $client = Contractor::getById($clientId);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER
            || (is_int($reseller) && $client->Seller->id !== $reseller)
            || ($reseller instanceof Seller && $client->Seller->id !== $reseller->id)) {
            throw new \Exception('сlient not found!', 400);
        }
        return $client;
    }

    /** Получение сотрудника
     * @param int $employeeId - id сотрудника
     * @param string $type - тип сотрудника
     * @return Employee
     * @throws \Exception
     */
    private function getEmployee(int $employeeId, string $type = 'Employee'): Employee {
        $employee = Employee::getById($employeeId);
        if ($employee === null) {
            throw new \Exception("$type not found!", 400);
        }
        return $employee;
    }

    /** Получение разницы
     * @param int $notificationType - тип уведомления
     * @param array|null $differences - разница
     * @param int $resellerId - id реселлера
     * @return mixed
     * @throws \Exception
     */
    private function getDifferences(int $notificationType, ?array $differences, int $resellerId) {
        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', null, $resellerId);
        }
        if ($notificationType === self::TYPE_CHANGE && !empty($differences['from']) && !empty($differences['to'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName($differences['from']),
                'TO' => Status::getName($differences['to']),
            ], $resellerId);
        }
        throw new \Exception('Invalid notificationType', 400);
    }

    /** Получение имени из контрагента
     * @param Contractor $contractor - контрагент
     * @return string
     */
    private function getNameFromContractor(Contractor $contractor): string {
        $name = $contractor->getFullName();
        return empty($name) ? $contractor->name : $name;
    }

    /** Валидация данных шаблона
     * @param array $templateData - данные шаблона
     * @return void
     * @throws \Exception
     */
    private function validateTemplateData(array $templateData): void {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    /** Отправка сообщения на Email тем сотрудникам, кому разрешено отправлять уведомления
     * @param int $resellerId - id реселлера
     * @param array $templateData - данные шаблона
     * @return bool
     */
    private function sendMessageEmailByPermit(int $resellerId, array $templateData): bool {
        $emailFrom = getResellerEmailFrom($resellerId);
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (empty($emails))
            return false;

        foreach ($emails as $email) {
            MessagesClient::sendMessage([
                0 => [ // MessageTypes::EMAIL
                    'emailFrom' => $emailFrom,
                    'emailTo' => $email,
                    'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                    'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                ],
            ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
        }
        return true;
    }

    /** Отправить ли клиенту уведомление
     * @param int $notificationType - тип уведомления
     * @param array|null $differences - разница
     * @return bool
     */
    private function isSendClientNotification(int $notificationType, ?array $differences): bool {
        // Шлём клиентское уведомление, только если произошла смена статуса
        return $notificationType !== self::TYPE_CHANGE && !empty($differences['to']);
    }

    /** Метод для отправки уведомлений
     * @return array
     * @throws \Exception
     */
    public function doOperation(): array {
        // Получаем данные из запроса
        $data = $this->getRequiestData();

        $resellerId = $data['resellerId'];
        $notificationType = (int)$data['notificationType'];
        $reseller = $this->getReseller($resellerId);
        $client = $this->getClient($data['clientId'], $reseller);
        $creator = $this->getEmployee($data['creatorId'], 'Creator');
        $expert = $this->getEmployee($data['expertId'], 'Expert');

        // Результат работы метода
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        // Собираем данные для шаблона. Все параметры уже отвалидированы (кроме результатов выполнения методов, которые,
        // по-хорошему, должны валидироваться внутри себя
        $templateData = [
            'COMPLAINT_ID' => $data['complaintId'],
            'COMPLAINT_NUMBER' => $data['complaintNumber'],
            'CREATOR_ID' => $data['creatorId'],
            'CREATOR_NAME' => $this->getNameFromContractor($creator),
            'EXPERT_ID' => $data['expertId'],
            'EXPERT_NAME' => $this->getNameFromContractor($expert),
            'CLIENT_ID' => $data['clientId'],
            'CLIENT_NAME' => $this->getNameFromContractor($client),
            'CONSUMPTION_ID' => $data['consumptionId'],
            'CONSUMPTION_NUMBER' => $data['consumptionNumber'],
            'AGREEMENT_NUMBER' => $data['agreementNumber'],
            'DATE' => $data['date'],
            'DIFFERENCES' => $this->getDifferences($notificationType, $data['differences'], $resellerId)
        ];

        $this->validateTemplateData($templateData);

        $emailFrom = getResellerEmailFrom($resellerId);
        $result['notificationEmployeeByEmail'] = $this->sendMessageEmailByPermit($resellerId, $templateData);


        if ($this->isSendClientNotification($notificationType, $data['differences'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo' => $client->email,
                        'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $error = null;
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
