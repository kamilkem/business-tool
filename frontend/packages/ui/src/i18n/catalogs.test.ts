/// <reference types="node" />
import { existsSync, readdirSync, readFileSync } from "node:fs"
import { join } from "node:path"
import { describe, expect, test } from "vitest"

import { LANGUAGES, SOURCE_LANGUAGE, type Language } from "./languages"

/**
 * Every catalog of the workspace (ADR 0028 §4): `<app or package>/src/locales/<language>/<namespace>.json`.
 * English is the source; every other language must have exactly its keys, every plural form the
 * language needs (`Intl.PluralRules`), the same `{{placeholders}}` and no empty text. CI runs this
 * with the other unit tests, so a missing translation fails the PR.
 */
const FRONTEND = join(import.meta.dirname, "../../../..")
const PLURAL_SUFFIX = /_(zero|one|two|few|many|other)$/

type Catalog = Map<string, string>

function flatten(value: unknown, prefix = "", into: Catalog = new Map()): Catalog {
  if (typeof value === "string") {
    into.set(prefix, value)
  } else if (typeof value === "object" && value !== null) {
    for (const [key, child] of Object.entries(value)) flatten(child, prefix ? `${prefix}.${key}` : key, into)
  } else {
    throw new Error(`${prefix}: a catalog holds only strings and nested objects`)
  }
  return into
}

function localeDirectories(): string[] {
  const roots = ["admin", "timeclock", ...readdirSync(join(FRONTEND, "packages")).map((name) => `packages/${name}`)]
  return roots.map((root) => join(FRONTEND, root, "src/locales")).filter((directory) => existsSync(directory))
}

function namespacesOf(directory: string, language: Language): string[] {
  const path = join(directory, language)
  return existsSync(path) ? readdirSync(path).filter((file) => file.endsWith(".json")) : []
}

function load(directory: string, language: Language, file: string): Catalog | null {
  const path = join(directory, language, file)
  return existsSync(path) ? flatten(JSON.parse(readFileSync(path, "utf8"))) : null
}

/** `{ "shifts": ["one", "other"] }` for plural keys, `{ "title": [] }` for the rest. */
function keysOf(catalog: Catalog): Map<string, string[]> {
  const keys = new Map<string, string[]>()
  for (const key of catalog.keys()) {
    const match = PLURAL_SUFFIX.exec(key)
    const base = match ? key.slice(0, match.index) : key
    keys.set(base, [...(keys.get(base) ?? []), ...(match ? [match[1]!] : [])].sort())
  }
  return keys
}

function placeholders(text: string): string[] {
  return [...text.matchAll(/\{\{\s*([\w.]+)[^}]*\}\}/g)].map((match) => match[1]!).sort()
}

function problems(source: Catalog, catalog: Catalog | null, language: Language): string[] {
  if (catalog === null) return ["the file is missing"]
  const found: string[] = []
  const pluralForms = [...new Intl.PluralRules(language).resolvedOptions().pluralCategories].sort()
  const expected = keysOf(source)
  const actual = keysOf(catalog)
  for (const [key, forms] of expected) {
    const translated = actual.get(key)
    if (translated === undefined) found.push(`missing: ${key}`)
    else if (forms.length > 0 && translated.join() !== pluralForms.join())
      found.push(`${key}: plural forms ${translated.join(", ")}; ${language} needs ${pluralForms.join(", ")}`)
    else if (forms.length === 0 && translated.length > 0) found.push(`${key}: plural forms, but English has none`)
  }
  for (const key of actual.keys()) if (!expected.has(key)) found.push(`not in English (stale): ${key}`)
  const englishPlaceholders = new Map([...source].map(([key, text]) => [key.replace(PLURAL_SUFFIX, ""), placeholders(text)]))
  for (const [key, text] of catalog) {
    if (text.trim() === "") found.push(`empty: ${key}`)
    const wanted = englishPlaceholders.get(key.replace(PLURAL_SUFFIX, ""))
    if (wanted && placeholders(text).join() !== wanted.join())
      found.push(`${key}: placeholders ${placeholders(text).join(", ") || "none"}; English has ${wanted.join(", ") || "none"}`)
  }
  return found
}

describe("translation catalogs", () => {
  const directories = localeDirectories()

  test("test_it_finds_the_shared_ui_catalog", () => {
    expect(directories.some((directory) => directory.endsWith("packages/ui/src/locales"))).toBe(true)
  })

  for (const directory of directories) {
    for (const file of namespacesOf(directory, SOURCE_LANGUAGE)) {
      const source = load(directory, SOURCE_LANGUAGE, file)!
      const name = `${directory.slice(FRONTEND.length + 1)}/*/${file}`

      test(`test_it_has_english_plural_forms_in_${name}`, () => {
        expect(problems(source, source, SOURCE_LANGUAGE)).toEqual([])
      })

      for (const language of LANGUAGES.filter((language) => language !== SOURCE_LANGUAGE)) {
        test(`test_it_has_every_key_of_${name}_in_${language}`, () => {
          expect(problems(source, load(directory, language, file), language)).toEqual([])
        })
      }
    }

    for (const language of LANGUAGES) {
      test(`test_it_has_no_${language}_namespace_without_english_in_${directory.slice(FRONTEND.length + 1)}`, () => {
        const english = namespacesOf(directory, SOURCE_LANGUAGE)
        expect(namespacesOf(directory, language).filter((file) => !english.includes(file))).toEqual([])
      })
    }
  }
})
