/**
 * Error codes shared by the engine, players and adapters.
 *
 * - `invalid`: a definition, state or action document is malformed, stale or
 *   inconsistent (PHP: InvalidDefinitionException).
 * - `illegal`: a well-formed action the rules forbid now (PHP:
 *   IllegalActionException).
 * - `unsupported`: the mode or capability is not available.
 * - `destroyed`: the engine was destroyed.
 * - `load`: fetching or decoding a definition failed.
 * - `storage`: browser persistence failed (unavailable, full, conflict).
 */
export type PuzzleErrorCode = 'invalid' | 'illegal' | 'unsupported' | 'destroyed' | 'load' | 'storage';

export class PuzzleError extends Error {
  readonly code: PuzzleErrorCode;

  constructor(code: PuzzleErrorCode, message: string, options?: { cause?: unknown }) {
    super(message, options);
    this.name = 'PuzzleError';
    this.code = code;
  }
}

export class InvalidDocumentError extends PuzzleError {
  constructor(message: string) {
    super('invalid', message);
    this.name = 'InvalidDocumentError';
  }
}

export class IllegalActionError extends PuzzleError {
  constructor(message: string) {
    super('illegal', message);
    this.name = 'IllegalActionError';
  }
}
