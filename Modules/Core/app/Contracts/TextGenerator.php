<?php

namespace Modules\Core\Contracts;

/**
 * Ask a language model for some prose.
 *
 * **Why this interface exists at all.** `Modules\Platform` already carries a
 * complete assistant — five drivers, an `AssistantProvider` row holding an
 * encrypted key, a settings page where the operator picks the provider and
 * model, and a fake driver for tests. Any feature that needs generated text
 * should use it rather than opening a second connection to somebody's API with a
 * second key configured somewhere else.
 *
 * But Platform is the edge module: it may depend on any other module's
 * `Contracts` and nothing may depend on it, because it is the API gateway and
 * the arrow has to point one way. So a feature module cannot reach into it.
 *
 * This is the same shape as the answer already used elsewhere:
 * `Modules\Social` stores files without knowing `Modules\Data` exists, by
 * calling `Modules\Data\Contracts\AttachmentService`. Core owns the interface,
 * Platform binds the implementation, and the feature module depends on Core —
 * which it already does, unavoidably, for everything else.
 *
 * ---
 *
 * **Deliberately smaller than the assistant behind it.** No tools, no
 * conversation, no streaming, no choice of provider. A caller here wants one
 * piece of text from one prompt; everything else is what
 * `Modules\Platform\Services\Assistant\AssistantConversation` is for, and a
 * caller that genuinely needs it belongs in Platform rather than behind this.
 *
 * Which provider answers is not a parameter, and that is a decision rather than
 * an omission: the operator already chooses a default on the assistant settings
 * page, and letting each feature name its own provider would be a second place
 * that choice lives — one of which would be wrong the day the key is replaced.
 */
interface TextGenerator
{
    /**
     * Why no text can be generated at all, or null if it can.
     *
     * The string is shown to an operator, so it says what is missing and where to
     * fix it. Separate from a failed call for the same reason every source and
     * driver in this application separates them: no provider configured is a
     * state of the install that will not fix itself on retry, and a refused
     * request is a bad afternoon.
     */
    public function unavailableReason(): ?string;

    /**
     * One prompt in, one piece of text out.
     *
     * @param  string  $prompt  the instruction and its subject matter
     * @param  string|null  $system  standing instructions, when the model supports them
     * @param  int|null  $maxTokens  a ceiling, when the caller knows one
     *
     * @throws TextGenerationFailed when no provider is configured, the provider
     *                              refuses, or it answers with no text
     */
    public function generate(string $prompt, ?string $system = null, ?int $maxTokens = null): string;
}
