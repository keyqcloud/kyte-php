<?php

namespace Kyte\Mvc\Controller;

class FunctionController extends ModelController
{
    // Configuration for function types and their templates
    private const FUNCTION_TYPES = [
        'hook_init' => [
            'template' => "public function hook_init() {\r\n\t\r\n}\r\n",
            'is_hook' => true
        ],
        'hook_auth' => [
            'template' => "public function hook_auth() {\r\n\t\r\n}\r\n",
            'is_hook' => true
        ],
        'hook_prequery' => [
            'template' => "public function hook_prequery(\$method, &\$field, &\$value, &\$conditions, &\$all, &\$order) {\r\n{switch_statement}}\r\n",
            'is_hook' => true
        ],
        'hook_preprocess' => [
            'template' => "public function hook_preprocess(\$method, &\$r, &\$o = null) {\r\n{switch_statement}}\r\n",
            'is_hook' => true
        ],
        'hook_response_data' => [
            'template' => "public function hook_response_data(\$method, \$o, &\$r = null, &\$d = null) {\r\n{switch_statement}}\r\n",
            'is_hook' => true
        ],
        'hook_process_get_response' => [
            'template' => "public function hook_process_get_response(&\$r) {\r\n\r\n}\r\n",
            'is_hook' => true
        ],
        'new' => [
            'template' => "public function new(\$data) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'update' => [
            'template' => "public function update(\$field, \$value, \$data) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'get' => [
            'template' => "public function get(\$field, \$value) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'delete' => [
            'template' => "public function delete(\$field, \$value) {\r\n\r\n}\r\n",
            'is_hook' => false
        ]
    ];

    private const SWITCH_STATEMENT = "\tswitch (\$method) {\r\n\t\tcase 'new':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'update':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'get':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'delete':\r\n\t\t\tbreak;\r\n\r\n\t\tdefault:\r\n\t\t\tbreak;\r\t}\r\n";

    // Methods that require controller code base regeneration
    private const REGENERATION_METHODS = ['update', 'delete'];

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $this->processNewFunction($r);
                break;
            case 'update':
                $this->processUpdateFunction($r);
                break;
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
            case 'get':
                $this->decompressCode($r);
                break;
            case 'update':
            case 'delete':
                $this->handleCodeRegeneration($o, $method);
                if ($method === 'update') {
                    $this->decompressCode($r);
                }
                break;
            default:
                break;
        }
    }

    /**
     * Process new function creation
     */
    private function processNewFunction(array &$r): void
    {
        $type = $r['type'] ?? '';
        
        if (!isset(self::FUNCTION_TYPES[$type])) {
            throw new \InvalidArgumentException("Invalid function type: {$type}");
        }

        $this->validateFunctionUniqueness($r['controller'], $type);
        
        $config = self::FUNCTION_TYPES[$type];
        $code = $this->generateCodeFromTemplate($config['template']);
        
        $r['code'] = bzcompress($code, 9);
    }

    /**
     * Process function update
     */
    private function processUpdateFunction(array &$r): void
    {
        $r['code'] = bzcompress($r['code'], 9);
    }

    /**
     * Validate that function doesn't already exist for the controller
     */
    private function validateFunctionUniqueness(int $controllerId, string $type): void
    {
        $functionModel = new \Kyte\Core\Model(constant("Function"));
        $functionModel->retrieve('controller', $controllerId, false, [
            ['field' => 'type', 'value' => $type]
        ]);

        if ($functionModel->count() > 0) {
            $config = self::FUNCTION_TYPES[$type];
            $errorType = $config['is_hook'] ? 'Hook' : 'Override';
            throw new \Exception("{$errorType} of type {$type} already exists for this controller.");
        }
    }

    /**
     * Generate code from template
     */
    private function generateCodeFromTemplate(string $template): string
    {
        return str_replace('{switch_statement}', self::SWITCH_STATEMENT, $template);
    }

    /**
     * Decompress code data
     */
    private function decompressCode(array &$r): void
    {
        if (isset($r['code'])) {
            $r['code'] = bzdecompress($r['code']);
        }
    }

    /**
     * Handle controller code base regeneration
     */
    private function handleCodeRegeneration($o, string $method): void
    {
        if (!in_array($method, self::REGENERATION_METHODS)) {
            return;
        }

        $ctrl = new \Kyte\Core\ModelObject(constant("Controller"));
        if (!$ctrl->retrieve("id", $o->controller)) {
            throw new \Exception("Unable to find specified controller.");
        }

        // Update code base and save to file
        ControllerController::generateCodeBase($ctrl);
    }
}