# Spora: Architectural Principles

This document serves as the north star for Spora's source code architecture and operational philosophy. Any new features, tools, or UI components should map perfectly to this model.

## 1. The "Digital Employee" MVP
Spora is "The WordPress of AI Agents," built on the concept of **"My Assistant"**—a single, highly autonomous AI assistant configured uniquely for the user. 
- **The Backpack:** Spora is equipped via a UI dashboard where users define its Tools, API connections, and settings.
- **The Engine:** While the UI is simplified to "My Assistant," the underlying database uses an `agent_id` structure. This ensures the future evolution into a multi-agent orchestration tool requires absolutely zero database structural refactoring.

## 2. Tool Taxonomy (Input/Output Isolation)
Spora categorizes tools strictly into two interfaces to resolve the core fear of autonomous AI: "Is it going to break something?"

### A. Input Tools (The Senses & Imagination)
These implement Spora's `InputToolInterface`. 
- **Rule:** Read-only operations or internal asset generation. This includes searching the web, querying a database, or even **Generative AI** (like generating an image or a document). 
- **Behavior:** Entirely safe. Generative tools (like DALL-E) are considered "Inputs" because they simply return data (an image URL) back into Spora's context window. They do not affect the external world. The Agent may invoke these tools autonomously at any time to query information or generate assets. They require *no human approval*.
  
> [!NOTE]
> If Spora generates an image, the human approval happens *later*, when Spora attempts to pass that generated image URL to an `OutputTool` (like sending it in a Slack message).

### B. Output Tools (The Hands)
These implement Spora's `OutputToolInterface`.
- **Rule:** Write actions or actions that affect the real world (e.g., Sending an Email, Posting a Tweet, Creating a Calendar Event).
- **Behavior:** Extremely structured, mandatory Human-in-the-loop behavior. Calling an Output Tool instantly suspends the Agent's operation.

## 3. The Orchestrator Loop & State Machine
Spora requires a *custom-built* Agent Orchestrator. While PHP libraries (like Prism or LLM-Chain) exist, they use synchronous `while()` loops that make it nearly impossible to pause execution across HTTP requests. Spora's loop relies on the SQLite database and `symfony/messenger` queue.

**The Loop Structure (`max_steps` limited):**
A single Agent Task has a `run_count`. To prevent infinite loops (and massive API bills), the Orchestrator enforces a strict limit (e.g., 10 iterations). 

1. **Think:** Spora sends the System Prompt (Recipe), History, and Backpack Tools to the LLM.
2. **Act (Input Tool):** If the LLM decides to use an `InputTool` (e.g., SearchWeb), the Orchestrator executes it instantly, appends the result to the Task history, increments `run_count`, and loops back to Step 1.
3. **Pause (Output Tool):** If the LLM decides to use an `OutputTool` (e.g., SendEmail):
   - The Orchestrator intercepts the call.
   - Spora serializes the current state (memory + exactly what arguments the Agent passed to the tool) into the SQLite database as a `PENDING_APPROVAL` status.
   - *The PHP script gracefully stops entirely.* This is crucial for shared hosting.
4. **The Notification & Review:** Spora notifies the User via the Dashboard UI (and eventual push notifications/emails) that an action requires review. The user approves, edits, or rejects the drafted action.
5. **Resume (Queue Dispatch):** If approved via the UI, an API call is made. Spora executes the tool, logs it, and dispatches a *new* Message onto the queue to "wake" the agent back up (Step 1) so it can finish its workflow.
6. **Complete:** If the LLM returns standard text instead of a tool call, the Orchestrator marks the Task as `COMPLETED`.
