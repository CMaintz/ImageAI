<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Builder\Prompt;

/**
 * Builder for constructing system instructions using the Builder Pattern
 *
 * System instructions set the AI persona and define behavioral rules.
 * Supports different instruction types: batch analysis, scene generation, etc.
 */

// phpcs:disable Generic.Files.LineLength.TooLong
class SystemInstructionBuilder
{
    private string $persona = '';
    private string $objective = '';
    private array $requirements = [];
    private array $principles = [];
    private array $guidelines = [];
    private ?string $closingNote = null;

    /**
     * Configure for batch analysis instructions
     */
    public function forBatchAnalysis(): self
    {
        $this->persona = 'Batch Art Analyst and E-commerce Content Specialist';
        $this->objective = 'process a series of artwork images and generate SEO metadata, product descriptions, and filtering properties for each one, in multiple languages';

        $this->requirements = [
            'Your **sole output** must be a single, valid JSON object.',
            'The root object MUST have a single top-level key: "results".',
            'The "results" key MUST contain an ARRAY of objects.',
            'You MUST generate exactly one object in the "results" array for EACH image/product provided, in the SAME ORDER they were given.',
            'Each object in the "results" array MUST contain: `productId` (string), `analysisResultId` (string), `properties` (object), and `analysis-data` (array).',
            'The `productId` and `analysisResultId` MUST exactly match the values provided in the prompt.',
            'The `analysis-data` key MUST contain an ARRAY of objects, one for each requested language. Each of these objects MUST have a "language" key (e.g., "en-GB") and an "analysis" key containing the translated content.',
        ];

        $this->principles = [];
        $this->guidelines = [];
        $this->closingNote = null;

        return $this;
    }

    /**
     * Configure for scene generation instructions
     */
    public function forSceneGeneration(): self
    {
        $this->persona = 'professional interior photographer specializing in real estate and e-commerce photography for art print retailers';
        $this->objective = 'Create photorealistic room scenes that would be ideal for showcasing wall art. The images will be used to digitally composite artwork, so wall visibility and lighting are important.';

        $this->requirements = [];

        $this->principles = [
            'Use professional interior photography techniques',
            'Maintain realistic spatial relationships and proper scale',
            'Apply natural, flattering lighting throughout the scene',
            'Create depth through layered composition',
            'Ensure sharp focus and proper exposure',
            'Create detailed, realistic textures'
        ];

        $this->guidelines = [
            'Rooms should feel aspirational yet achievable - like a well-designed home',
            'Include realistic furniture, decor, and lifestyle elements',
            'Avoid sterile or overly staged appearances',
        ];

        $this->closingNote = "Think like an interior photographer shooting for a lifestyle magazine or art gallery lookbook.";

        return $this;
    }

    /**
     * Set the AI persona/role
     */
    public function setPersona(string $persona): self
    {
        $this->persona = $persona;
        return $this;
    }

    /**
     * Set the primary objective
     */
    public function setObjective(string $objective): self
    {
        $this->objective = $objective;
        return $this;
    }

    /**
     * Add a requirement (absolute/strict rules)
     */
    public function addRequirement(string $requirement): self
    {
        $this->requirements[] = $requirement;
        return $this;
    }

    /**
     * Add a principle (how to approach the task)
     */
    public function addPrinciple(string $principle): self
    {
        $this->principles[] = $principle;
        return $this;
    }

    /**
     * Add a guideline (style/quality guidance)
     */
    public function addGuideline(string $guideline): self
    {
        $this->guidelines[] = $guideline;
        return $this;
    }

    /**
     * Set a closing note
     */
    public function setClosingNote(string $note): self
    {
        $this->closingNote = $note;
        return $this;
    }

    /**
     * Build the complete system instruction
     */
    public function build(): string
    {
        $instruction = "You are a {$this->persona}.\n\n";
        $instruction .= "YOUR PRIMARY OBJECTIVE: {$this->objective}\n";

        if (!empty($this->requirements)) {
            $instruction .= "\nABSOLUTE REQUIREMENTS:\n";
            foreach ($this->requirements as $index => $requirement) {
                $num = $index + 1;
                $instruction .= "{$num}. {$requirement}\n";
            }
        }

        if (!empty($this->principles)) {
            $instruction .= "\nPRINCIPLES:\n";
            foreach ($this->principles as $principle) {
                $instruction .= "- {$principle}\n";
            }
        }

        if (!empty($this->guidelines)) {
            $instruction .= "\nGUIDELINES:\n";
            foreach ($this->guidelines as $guideline) {
                $instruction .= "- {$guideline}\n";
            }
        }

        if ($this->closingNote !== null) {
            $instruction .= "\n{$this->closingNote}";
        }

        return trim($instruction);
    }

    /**
     * Reset the builder to initial state
     */
    public function reset(): self
    {
        $this->persona = '';
        $this->objective = '';
        $this->requirements = [];
        $this->principles = [];
        $this->guidelines = [];
        $this->closingNote = null;
        return $this;
    }
}
