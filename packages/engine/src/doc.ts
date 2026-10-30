import { InvalidDocumentError } from './errors.js';

/**
 * Narrowing helpers for external JSON (definitions, states, actions). They
 * mirror src/Game/Doc.php so both engines accept and reject the same input.
 */

export type JsonObject = { readonly [key: string]: unknown };

export const ID_PATTERN = /^[a-z][a-z0-9_-]{0,31}$/;
const MIN_INT = Number.MIN_SAFE_INTEGER;
const MAX_INT = Number.MAX_SAFE_INTEGER;

export function object(value: unknown, path: string): JsonObject {
  // PHP decodes {} and [] to the same empty array; accept both as empty.
  if (Array.isArray(value) && value.length === 0) {
    return {};
  }
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new InvalidDocumentError(`${path} must be an object.`);
  }
  return value as JsonObject;
}

export function list(value: unknown, path: string, max = 4096): readonly unknown[] {
  if (typeof value === 'object' && value !== null && !Array.isArray(value) && Object.keys(value).length === 0) {
    return [];
  }
  if (!Array.isArray(value)) {
    throw new InvalidDocumentError(`${path} must be an array.`);
  }
  if (value.length > max) {
    throw new InvalidDocumentError(`${path} has more than ${max} entries.`);
  }
  return value as readonly unknown[];
}

export function keys(value: JsonObject, allowed: readonly string[], path: string): void {
  const unknown = Object.keys(value).filter((key) => !allowed.includes(key));
  if (unknown.length > 0) {
    throw new InvalidDocumentError(`${path} has unknown field(s): ${unknown.join(', ')}.`);
  }
}

export function has(value: JsonObject, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(value, key);
}

export function int(value: JsonObject, key: string, path: string, min = MIN_INT, max = MAX_INT): number {
  if (!has(value, key)) {
    throw new InvalidDocumentError(`${path}.${key} is required.`);
  }
  return intValue(value[key], `${path}.${key}`, min, max);
}

export function intValue(value: unknown, path: string, min = MIN_INT, max = MAX_INT): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value)) {
    throw new InvalidDocumentError(`${path} must be an integer.`);
  }
  if (value < min || value > max) {
    throw new InvalidDocumentError(`${path} must be between ${min} and ${max}, got ${value}.`);
  }
  return value;
}

export function string(value: JsonObject, key: string, path: string, maxLength = 2048): string {
  if (!has(value, key)) {
    throw new InvalidDocumentError(`${path}.${key} is required.`);
  }
  return stringValue(value[key], `${path}.${key}`, maxLength);
}

export function stringValue(value: unknown, path: string, maxLength = 2048): string {
  if (typeof value !== 'string') {
    throw new InvalidDocumentError(`${path} must be a string.`);
  }
  if (utf8Length(value) > maxLength) {
    throw new InvalidDocumentError(`${path} is longer than ${maxLength} bytes.`);
  }
  return value;
}

export function bool(value: JsonObject, key: string, path: string): boolean {
  const item = value[key];
  if (!has(value, key) || typeof item !== 'boolean') {
    throw new InvalidDocumentError(`${path}.${key} must be a boolean.`);
  }
  return item;
}

export function id(value: unknown, path: string): string {
  const text = stringValue(value, path, 32);
  if (!ID_PATTERN.test(text)) {
    throw new InvalidDocumentError(`${path} is not a valid identifier: "${text}".`);
  }
  return text;
}

function utf8Length(text: string): number {
  let bytes = 0;
  for (const char of text) {
    const code = char.codePointAt(0) ?? 0;
    bytes += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
  }
  return bytes;
}
