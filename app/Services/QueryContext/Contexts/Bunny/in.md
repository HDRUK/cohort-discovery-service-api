Example query:

```json
{
    "id": "b88c653d-6306-45cf-be3e-bb7280839d0a",
    "rules": [
        {
            "id": "1519181c-0cdd-436f-acaa-11ab06c6fad9",
            "exclude": false,
            "rules": [
                {
                    "id": "ce7f2f2d-6c9d-4268-9c0c-a370a428fcf9",
                    "exclude": false,
                    "rule": {
                        "concept": {
                            "concept_id": 3955320,
                            "description": "Moderna - SARS-CoV-2 (COVID-19) vaccine",
                            "category": "Drug",
                            "children": []
                        }
                    },
                    "valid": true
                },
                {
                    "id": "32109149-e79a-4d3b-b170-8ae2a3a1097b",
                    "combinator": "or",
                    "exclude": false,
                    "valid": true
                },
                {
                    "id": "375e2a79-7809-4bdf-b546-bafc5c7c8e64",
                    "exclude": false,
                    "rule": {
                        "concept": {
                            "concept_id": 3955321,
                            "description": "Pfizer - SARS-CoV-2 (COVID-19) vaccine",
                            "category": "Drug",
                            "children": []
                        }
                    },
                    "valid": true
                }
            ],
            "valid": true
        },
        {
            "id": "b9eac1a6-3a2c-4048-90f1-4c3c81eee3a5",
            "combinator": "and",
            "exclude": false,
            "valid": true
        },
        {
            "id": "11dbff8d-e73c-491d-b125-d892c6021ba4",
            "exclude": true,
            "rule": {
                "concept": {
                    "concept_id": 3955322,
                    "description": "Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222",
                    "category": "Drug",
                    "children": []
                }
            },
            "valid": true
        },
        {
            "id": "7c4b6e63-2e2d-4ac0-a7c9-5403f583e9ac",
            "combinator": "and",
            "exclude": false,
            "valid": true
        },
        {
            "id": "ba2d2aec-5afa-47fb-a418-c831af4f8572",
            "rule": {
                "concept": {
                    "concept_id": 3959231,
                    "description": "Close contact with confirmed COVID-19 case person/patient",
                    "category": "Observation",
                    "children": []
                }
            },
            "valid": true
        },
        {
            "id": "50791fe7-645d-425b-8920-7a43195bef0a",
            "combinator": "and",
            "valid": true
        },
        {
            "id": "0081fa3f-e496-46e6-bd8f-fbea9a317f17",
            "exclude": false,
            "rule": {
                "concept": {
                    "name": "3955313",
                    "concept_id": 3955313,
                    "description": "SARS-CoV-2 antibody to nucleocapsid (N) protein present",
                    "category": "Measurement",
                    "children": []
                }
            },
            "valid": true
        },
        {
            "id": "633dc85f-d719-428d-aec7-0fd89834fe76",
            "combinator": "and",
            "valid": true
        },
        {
            "id": "39266fe7-9420-4aee-b6f1-76793a71f99d",
            "exclude": false,
            "rule": {
                "concept": {
                    "name": "443597",
                    "concept_id": 443597,
                    "description": "Chronic kidney disease stage 3",
                    "category": "Condition",
                    "children": [
                        {
                            "concept_id": 601163,
                            "description": "Chronic kidney disease stage 3 due to drug induced diabetes mellitus",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 37019193,
                            "description": "Anemia co-occurrent and due to chronic kidney disease stage 3",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 43531653,
                            "description": "Chronic kidney disease stage 3 due to type 2 diabetes mellitus",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 44782691,
                            "description": "Chronic kidney disease stage 3 due to hypertension",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 44792230,
                            "description": "Chronic kidney disease stage 3 with proteinuria",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 44792231,
                            "description": "Chronic kidney disease stage 3 without proteinuria",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 45763854,
                            "description": "Chronic kidney disease stage 3A",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 45763855,
                            "description": "Chronic kidney disease stage 3B",
                            "category": "Condition"
                        },
                        {
                            "concept_id": 45771075,
                            "description": "Chronic kidney disease stage 3 due to type 1 diabetes mellitus",
                            "category": "Condition"
                        }
                    ]
                }
            },
            "valid": true
        }
    ],
    "valid": true
}
```

We have some code that converts this into a string

```js
queryToText = (node: RuleGroupType): string => {};
```

The above example converted to output:

People who received Moderna COVID-19 vaccine or Pfizer COVID-19 vaccine and received not (received Oxford, AstraZeneca COVID-19 vaccine) and observed with Close contact with confirmed COVID-19 case person and measured with SARS-CoV-2 antibody to nucleocapsid (N) protein present and diagnosed with Chronic kidney disease stage 3

We need to do the reverse with any type of user input.

## System Instructions

I was playing with something like this, which is an adaptation of how I was doing it before:

```js
"use server";

import { GoogleGenerativeAI } from "@google/generative-ai";

const apiKey = process.env.GEMINI_API_KEY || "";
const geminiModelName = process.env.GEMINI_MODEL_NAME || "gemini-2.5-flash";
const genAI = new GoogleGenerativeAI(apiKey);

const systemInstruction = `
You convert natural-language clinical queries into JSON that matches THIS EXACT SCHEMA.

Respond ONLY with JSON. No explanations, no extra text.

========================
SCHEMA
========================
Top-level:
{
  "rules": RuleNodeType[]
}

RuleNodeType is one of:

1) Group
{
  "rules": RuleNodeType[],
  "exclude": boolean?           // optional negation of the whole group
}

2) Leaf
{
  "rule": { "concept": string },
  "exclude": boolean?           // optional negation of the leaf
}

3) Operator
{
  "combinator": "and" | "or" | "followed_by"
}

========================
RULES
========================
- Use "exclude": true to express negation ("not", "without", "excluding") on a leaf or a group.
- Put operator objects BETWEEN siblings in "rules" (e.g., [Leaf, Operator, Leaf], or [Group, Operator, Leaf]).
- Use "or" for alternatives, "and" for conjunctions.
- Use "followed_by" for explicit ordering/temporal sequence ("then", "after", "subsequently").
- Nest groups for parentheses/precedence, e.g., (A or B) and C → [{Group(A or B)}, {and}, {Leaf C}].
- Concept strings should be short, natural phrases from the query (no codes needed).
- Only include the fields shown above; do NOT add IDs or other properties.
- You may omit "exclude" when false/unspecified.

========================
DOMAIN SCOPE
========================
Extract concepts for these domains only:
- Person (e.g., “Female”, “Age ≥ 50” if stated)
- Measurement (tests, lab findings)
- Observation (findings, encounters, exposures)
- Condition (diagnoses)
- Drug (drug exposures, vaccines, therapies)

You do NOT need to label the domain explicitly; just provide the concept string.

========================
EXAMPLES
========================

Example 1 — Alternatives (OR)
User: "Patients who received Moderna or Pfizer COVID-19 vaccine"
{
  "rules": [
    { "rule": { "concept": "Moderna COVID-19 vaccine" } },
    { "combinator": "or" },
    { "rule": { "concept": "Pfizer COVID-19 vaccine" } }
  ]
}

Example 2 — Exclusion
User: "received Moderna or Pfizer but not AstraZeneca"
{
  "rules": [
    {
      "rules": [
        { "rule": { "concept": "Moderna COVID-19 vaccine" } },
        { "combinator": "or" },
        { "rule": { "concept": "Pfizer COVID-19 vaccine" } }
      ]
    },
    { "combinator": "and" },
    { "rule": { "concept": "AstraZeneca COVID-19 vaccine" }, "exclude": true }
  ]
}

Example 3 — Multiple domains (AND)
User: "Close contact with a COVID-19 case and nucleocapsid antibody present and CKD stage 3"
{
  "rules": [
    { "rule": { "concept": "Close contact with confirmed COVID-19 case" } },
    { "combinator": "and" },
    { "rule": { "concept": "SARS-CoV-2 nucleocapsid antibody present" } },
    { "combinator": "and" },
    { "rule": { "concept": "Chronic kidney disease stage 3" } }
  ]
}

Example 4 — Sequence (FOLLOWED_BY)
User: "Positive PCR followed by hospitalization for COVID-19"
{
  "rules": [
    { "rule": { "concept": "Positive SARS-CoV-2 PCR test" } },
    { "combinator": "followed_by" },
    { "rule": { "concept": "Hospitalization for COVID-19" } }
  ]
}

Example 5 — Negated group
User: "Diabetes or hypertension but not any COVID-19 vaccination"
{
  "rules": [
    {
      "rules": [
        { "rule": { "concept": "Diabetes mellitus" } },
        { "combinator": "or" },
        { "rule": { "concept": "Hypertension" } }
      ]
    },
    { "combinator": "and" },
    {
      "rules": [
        { "rule": { "concept": "COVID-19 vaccination" } }
      ],
      "exclude": true
    }
  ]
}

Example 6 — Person concepts
User: "Females aged between 50 and 60 with diabetes"
{
  "rules": [
    { "rule": { "concept": "Female" } },
    { "combinator": "and" },
    { "rule": { "concept": "Age between 50 and 60" } },
    { "combinator": "and" },
    { "rule": { "concept": "Diabetes mellitus" } }
  ]
}

========================
RESPONSE POLICY
========================
- Return JSON ONLY.
- Do not include IDs.
- Include only the fields in the schema above.
- Ensure operators are placed between siblings and groups are nested as needed.
`;

const model = genAI.getGenerativeModel({
    model: geminiModelName,
    systemInstruction,
});

const getQueryFromInput = async (input: string) => {
    try {
        const promt = `User input was: ${input}`;
        const result = await model.generateContent(promt);
        const { response } = result;
        const text = response.text();

        const jsonStart = text.indexOf("{");
        const jsonEnd = text.lastIndexOf("}");
        if (jsonStart === -1 || jsonEnd === -1 || jsonEnd <= jsonStart) {
            throw new Error("Could not locate valid JSON in model response.");
        }

        const jsonString = text.slice(jsonStart, jsonEnd + 1);

        const parsed = JSON.parse(jsonString);

        return parsed;
    } catch (err) {
        console.error("Error generating query from input:", err);
        return {
            error: "Failed to parse query. Please try rephrasing your request.",
            details: err instanceof Error ? err.message : String(err),
        };
    }
};

export default getQueryFromInput;
```

Example query:

men who received the covid-19 vaccine and have ckd

Returns:

```json
{
    "rules": [
        {
            "rule": {
                "concept": "Male"
            }
        },
        {
            "combinator": "and"
        },
        {
            "rule": {
                "concept": "COVID-19 vaccine"
            }
        },
        {
            "combinator": "and"
        },
        {
            "rule": {
                "concept": "Chronic kidney disease"
            }
        }
    ]
}
```

Another example:

females who were observed with a cough or diagnosed with COPD or asthma

Returns:

```json
{
    "rules": [
        {
            "rule": {
                "concept": "Female"
            }
        },
        {
            "combinator": "and"
        },
        {
            "rules": [
                {
                    "rule": {
                        "concept": "Cough"
                    }
                },
                {
                    "combinator": "or"
                },
                {
                    "rule": {
                        "concept": "COPD"
                    }
                },
                {
                    "combinator": "or"
                },
                {
                    "rule": {
                        "concept": "Asthma"
                    }
                }
            ]
        }
    ]
}
```

We need some code to turn these text concepts into actually concepts

I.e.

```json
{
    "rule": {
        "concept": "Chronic kidney disease stage 3"
    }
}
```

Needs to become:

```json
{
    "rule": {
        "concept": {
            "name": "443597",
            "concept_id": 443597,
            "description": "Chronic kidney disease stage 3",
            "category": "Condition"
        }
    }
}
```

Idea:

-   Scan to find all concepts text (e.g. "Chronic kidney disease stage 3")
-   Send them to the BE
    -   BE looks this up in the OMOP concept tables / Distribution Table
    -   Finds the best matching concept for this text (with descendants?)
    -   Returns these candidates
-   FE code fills this in

Foresable issues:

-   Using Gemini API with such a long system instruction could hit rate limits quickly
-   Do we need to use other LLM APIs too?
-   How do we make sure they have a consistent response?
