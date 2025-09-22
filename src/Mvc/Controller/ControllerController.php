<?php

namespace Kyte\Mvc\Controller;

class ControllerController extends ModelController
{
    // Namespace patterns for validation
    private const KYTE_CORE_NAMESPACE = '\\Kyte\\Mvc\\Controller\\';
    private const CONTROLLER_SUFFIX = 'Controller';

    // Error messages
    private const ERROR_MESSAGES = [
        'name_exists_scope' => 'Controller name already exists in application scope.',
        'name_exists_new_scope' => 'New controller name already exists in application scope.',
        'name_kyte_core' => 'Controller name already in use by Kyte core API.',
        'name_custom' => 'Custom controller name already in use.',
        'model_not_found' => 'Unable to find specified data model.'
    ];

    // Template for generated code
    private const CODE_TEMPLATE = "class %sController extends \\Kyte\\Mvc\\Controller\\ModelController\r\n{\r\n%s}\r\n";
    private const SHIPYARD_INIT_TEMPLATE = "\tpublic function shipyard_init() {\r\n\t\t\$this->model = %s;\r\n\t}\r\n";

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $this->validateControllerName($r['name'], $r['application']);
                break;
            case 'update':
                $this->validateControllerUpdate($o, $r);
                break;
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
            case 'update':
                $this->regenerateControllerCode($o);
                break;
            case 'delete':
                $this->cleanupControllerFunctions($o);
                break;
            default:
                break;
        }
    }

    /**
     * Validate controller name for new controllers
     */
    private function validateControllerName(string $name, int $applicationId): void
    {
        $this->checkNameExistsInScope($name, $applicationId, 'name_exists_scope');
        $this->checkNameConflicts($name);
    }

    /**
     * Validate controller name for updates
     */
    private function validateControllerUpdate($originalController, array $updateData): void
    {
        // Only validate if the name is actually changing
        if ($originalController->name === $updateData['name']) {
            return;
        }

        $this->checkNameExistsInScope(
            $updateData['name'], 
            $updateData['application'], 
            'name_exists_new_scope'
        );
        $this->checkNameConflicts($updateData['name']);
    }

    /**
     * Check if controller name already exists in application scope
     */
    private function checkNameExistsInScope(string $name, int $applicationId, string $errorKey): void
    {
        $existingController = new \Kyte\Core\ModelObject(constant('Controller'));
        $conditions = [['field' => 'application', 'value' => $applicationId]];
        
        if ($existingController->retrieve('name', $name, $conditions)) {
            throw new \Exception(self::ERROR_MESSAGES[$errorKey]);
        }
    }

    /**
     * Check for name conflicts with existing classes
     */
    private function checkNameConflicts(string $name): void
    {
        $kyteCoreName = self::KYTE_CORE_NAMESPACE . $name . self::CONTROLLER_SUFFIX;
        $customName = $name . self::CONTROLLER_SUFFIX;

        if (class_exists($kyteCoreName)) {
            throw new \Exception(self::ERROR_MESSAGES['name_kyte_core']);
        }

        if (class_exists($customName)) {
            throw new \Exception(self::ERROR_MESSAGES['name_custom']);
        }
    }

    /**
     * Regenerate controller code and save to file
     */
    private function regenerateControllerCode($controller): void
    {
        self::generateCodeBase($controller);
    }

    /**
     * Clean up associated functions when controller is deleted
     */
    private function cleanupControllerFunctions($controller): void
    {
        $functions = new \Kyte\Core\Model(constant("Function"));
        $functions->retrieve("controller", $controller->id);
        
        foreach ($functions->objects as $function) {
            $function->delete();
        }
    }

    /**
     * Generate the complete code base for a controller
     */
    public static function generateCodeBase($controller): void
    {
        $functions = self::collectControllerFunctions($controller);
        $code = self::buildControllerCode($controller->name, $functions);
        
        $controller->save([
            'code' => bzcompress($code, 9),
        ]);
    }

    /**
     * Collect all functions for the controller
     */
    private static function collectControllerFunctions($controller): array
    {
        $functions = [];

        // Add shipyard_init if model is specified
        if (!empty($controller->dataModel)) {
            $functions[] = self::generateShipyardInit($controller->dataModel);
        }

        // Add custom functions
        $customFunctions = new \Kyte\Core\Model(constant("Function"));
        $customFunctions->retrieve("controller", $controller->id);
        
        foreach ($customFunctions->objects as $function) {
            $functions[] = bzdecompress($function->code);
        }

        return $functions;
    }

    /**
     * Generate shipyard_init function code
     */
    private static function generateShipyardInit(int $dataModelId): string
    {
        $model = new \Kyte\Core\ModelObject(DataModel);
        if (!$model->retrieve("id", $dataModelId)) {
            throw new \Exception(self::ERROR_MESSAGES['model_not_found']);
        }

        return sprintf(self::SHIPYARD_INIT_TEMPLATE, $model->name);
    }

    /**
     * Build the complete controller class code
     */
    private static function buildControllerCode(string $controllerName, array $functions): string
    {
        $functionsCode = '';
        if (!empty($functions)) {
            $functionsCode = "\r\n" . implode("\r\n", $functions) . "\r\n";
        }

        return sprintf(self::CODE_TEMPLATE, $controllerName, $functionsCode);
    }
}