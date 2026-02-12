## [1.1.0](https://github.com/HDRUK/project-daphne-api/compare/v1.0.0...v1.1.0) (2026-02-12)

### ✨ Features

* **DP-335:** ...but not really. Puts User lists behind auth middleware ([3d3a414](https://github.com/HDRUK/project-daphne-api/commit/3d3a414e87a7822daa0ef8999ab428d108af34ab)), closes [DP-335](DP-335)
* **DP-335:** #10 - api updates required for nlp changes ([36b81e7](https://github.com/HDRUK/project-daphne-api/commit/36b81e74dff7bdaa81ff5dd1a3011427758caeaf)), closes [DP-335](DP-335)
* **DP-335:** #17 Tweaks to handle constraints to ages, and those inferred as general query scope ([38441ef](https://github.com/HDRUK/project-daphne-api/commit/38441ef481f45609932a37205183bcac313053df)), closes [DP-335](DP-335)
* **DP-335:** #2 adds score and token criteria to the payload ([a36a397](https://github.com/HDRUK/project-daphne-api/commit/a36a397003235ee7726cd76e3d55d2d34cdad807)), closes [DP-335](DP-335)
* **DP-335:** #21 - changes to remove the eager strtolower killing acronyms for detection ([a72379d](https://github.com/HDRUK/project-daphne-api/commit/a72379d1dfde2c5ae8ac70f69b4347f6f0dd2769)), closes [DP-335](DP-335)
* **DP-335:** #3 - implements view count and logging to determine if anything is happening in dev ([dac7c11](https://github.com/HDRUK/project-daphne-api/commit/dac7c11af8afabe656a4e15275f0070bcb1ec8b9)), closes [DP-335](DP-335)
* **DP-335:** #5 logging batch size to see what we can reduce to for testing ([a0b6c5c](https://github.com/HDRUK/project-daphne-api/commit/a0b6c5cac0173e83dc3bca497bd45545271b4ad9)), closes [DP-335](DP-335)
* **DP-335:** Makes batchSize configurable for distribution file processing ([15ea1fc](https://github.com/HDRUK/project-daphne-api/commit/15ea1fc1b1b24adfba0313f0f4d1b9d806418d4f)), closes [DP-335](DP-335)
* **DP-345:** Add ability to delete queries alone and in bulk ([3c3fb26](https://github.com/HDRUK/project-daphne-api/commit/3c3fb265b6c95c76ccb960d5035536f37023af98)), closes [DP-345](DP-345)
* **DP-353:** Implements role sync fully ([303c8c9](https://github.com/HDRUK/project-daphne-api/commit/303c8c98273bc1ab6541312ce2f77d96b05af26a)), closes [DP-353](DP-353)
* **DP-433:** Ensures age constraints are applied to every rule built from nlp query parse ([55d6a49](https://github.com/HDRUK/project-daphne-api/commit/55d6a490917c0873e1428c2846abca0558f6778f)), closes [DP-433](DP-433)

### 🐛 Bug Fixes

* **DP-335:** #10 - fixes broken Dockerfile ([5956915](https://github.com/HDRUK/project-daphne-api/commit/5956915536088884fe547060630513ce4b822b71))
* **DP-370:** Fixes query timestamps after move to laravel generated ([bb2b3d0](https://github.com/HDRUK/project-daphne-api/commit/bb2b3d07d004c9573b3e01129c35721bbbddbc16))
* **DP-373:** Updates re-runs to only run against set collections ([2a49949](https://github.com/HDRUK/project-daphne-api/commit/2a4994970c64b732a5a5c40cbaa2e1a09ae36e1d)), closes [DP-373](DP-373)

## 1.0.0 (2026-01-08)

### ✨ Features

* **DP-101:** Improvements to the query parser by making use of new python service sitting on top of omop distributions view ([6c1a3e7](https://github.com/HDRUK/project-daphne-api/commit/6c1a3e7341cf41deeb0945684d1e3460d8cef5bd)), closes [DP-101](DP-101)
* **DP-110:** implement search, sort and such on collections ([ed7e867](https://github.com/HDRUK/project-daphne-api/commit/ed7e8671ef568c801af136708b4c8ae4d9873316)), closes [DP-110](DP-110)
* **DP-112:** Implements view for distributions and concepts from bunny jobs and job to update on refresh ([c531af1](https://github.com/HDRUK/project-daphne-api/commit/c531af18e6b791293e9aafb35b75d250497e8103)), closes [DP-112](DP-112)
* **DP-113:** Re-design query/task creation to run on endpoint that can be controlled via GCP scheduler ([875c3f9](https://github.com/HDRUK/project-daphne-api/commit/875c3f94daa073044359640ea9d430ffcc02e7ed)), closes [DP-113](DP-113) [endpoint](dpoint)
* **DP-114:** Implements full controllers for queries, including downloadables and re-runining ([eadc32d](https://github.com/HDRUK/project-daphne-api/commit/eadc32d144ab4a35e175e08fa92b7b3bab5ca112)), closes [DP-114](DP-114)
* **DP-233:** Update CollectionHostController for searching, sorting and filtering. Also pulls in line with ModelBackedRequest via strangle ([3608e84](https://github.com/HDRUK/project-daphne-api/commit/3608e84ede2191ecb76037f6bca1d1f9e8721c43)), closes [DP-233](DP-233)
* **DP-248:** Implements manually runnable distributions endpoint ([55b193e](https://github.com/HDRUK/project-daphne-api/commit/55b193e4843d82759f8ad606134cf54a063d3f3e)), closes [DP-248](DP-248) [endpoint](dpoint)
* **DP-250:** Implements Collection activity monitor api command that is callable via endpoint ([64cc2ea](https://github.com/HDRUK/project-daphne-api/commit/64cc2eabad2242e6f33b318bef44dae42b058665)), closes [DP-250](DP-250) [endpoint](dpoint)
* **DP-254:** Adds permissions for collection transitioning per role ([c96a00b](https://github.com/HDRUK/project-daphne-api/commit/c96a00b94b334da491723e4c6bc133d5887a709d)), closes [DP-254](DP-254)
* **DP-254:** Implements model states via custom package on Collections ([f4fe648](https://github.com/HDRUK/project-daphne-api/commit/f4fe648f1be00d26f78384dccacaf7697624414a)), closes [DP-254](DP-254)
* **DP-261:** Implements structure of CustodianNetworks ([140991e](https://github.com/HDRUK/project-daphne-api/commit/140991e3eb15768de95b8dbc71677b9984c46894)), closes [DP-261](DP-261)
* **DP-27:** Implement searching users having workgroup ([abd6b41](https://github.com/HDRUK/project-daphne-api/commit/abd6b41e364381e88babfec95de98b3b38449492)), closes [DP-27](DP-27)
* **DP-27:** Implements new user status to user model ([4a3f599](https://github.com/HDRUK/project-daphne-api/commit/4a3f599d092920295870d7e4435b93b13363eeba)), closes [DP-27](DP-27)
* **DP-27:** Implements sorting and search and new vs existing users ([07b274e](https://github.com/HDRUK/project-daphne-api/commit/07b274ec615c083cd3394549e712ae30164c3e40)), closes [DP-27](DP-27)
* **DP-283:** Allow filtering by model state ([3a84f04](https://github.com/HDRUK/project-daphne-api/commit/3a84f0418e13e48e3cf020aabf4ce37dd9392a4a)), closes [DP-283](DP-283)
* **DP-290:** Implements laravel-messenger and feature flagging on api ([edfbeb3](https://github.com/HDRUK/project-daphne-api/commit/edfbeb3eb77d56ff4f19b00bd2d00cffc9904541)), closes [DP-290](DP-290)
* **DP-314:** Allows override of jwt expiration ttl ([e0942f6](https://github.com/HDRUK/project-daphne-api/commit/e0942f6cd2f151169dd1ed68ce3a02cef19e6a40)), closes [DP-314](DP-314)
* **DP-334:** Updates to NLP query handling ([13c32c9](https://github.com/HDRUK/project-daphne-api/commit/13c32c9bcf952081f571cf9f71c81d8954052916)), closes [DP-334](DP-334)
* **DP-89:** Implements standalone and integrated mode for user auth ([b62e3bf](https://github.com/HDRUK/project-daphne-api/commit/b62e3bf2881ef1035420890cdebdf0cc514dfee5)), closes [DP-89](DP-89)
* **DP-94:** First implementation of rule builder via API to remove reliance on LLM for new query builder FE ([0b5e1ff](https://github.com/HDRUK/project-daphne-api/commit/0b5e1ff32935b07f5c99d526a811761ae98e7ceb)), closes [DP-94](DP-94)
* **DP-94:** properly honour groupings and further tests ([d4c8925](https://github.com/HDRUK/project-daphne-api/commit/d4c8925a90745437d827ab5674a7ee9c1aebf8d9)), closes [DP-94](DP-94)
* **DP-96:** Implements plugin system ([28d3d47](https://github.com/HDRUK/project-daphne-api/commit/28d3d473bb6fc234ad29f40f5fb789625c5ed1d5)), closes [DP-96](DP-96)
* **GAT-7670-2:** Implements generic workgroups within Daphne) ([fdbfe03](https://github.com/HDRUK/project-daphne-api/commit/fdbfe0326c9be8dfcbe73bf4309d213ef72464ed)), closes [GAT-7670-2](GAT-7670-2)
* **GAT-7670-3:** Implement remaining parts for workflows ([1f0dc96](https://github.com/HDRUK/project-daphne-api/commit/1f0dc964d830b6cced9fa8cce066b8b7997e30b9)), closes [GAT-7670-3](GAT-7670-3)
* **GAT-7670:** Implements oauth federated token exchange between GW and Daphne ([8df4a83](https://github.com/HDRUK/project-daphne-api/commit/8df4a831634e40b14d45b3ae9be1b84575f4f23c)), closes [GAT-7670](GAT-7670)
* **GAT-7670:** Workgroups being passed from Gateway to Daphne and honoured via endpoint protection via middleware ([980d979](https://github.com/HDRUK/project-daphne-api/commit/980d979bd5a8b7cdf27fef70d53722d005e71067)), closes [GAT-7670](GAT-7670) [Gateway](Gateway) [endpoint](dpoint)
* **GAT-7670:** Workgroups being passed from Gateway to Daphne and honoured via endpoint protection via middleware ([7636a00](https://github.com/HDRUK/project-daphne-api/commit/7636a006f2feacba48bdd46de68f20cf32dd2312)), closes [GAT-7670](GAT-7670) [Gateway](Gateway) [endpoint](dpoint)
* **GAT-7671:** Implement RBAC and workgroups ([026fa58](https://github.com/HDRUK/project-daphne-api/commit/026fa588cc56bf3ac2eec2545258adfabf892787)), closes [GAT-7671](GAT-7671)
* **GAT-7688:** Implements oauth2 ([669e5e6](https://github.com/HDRUK/project-daphne-api/commit/669e5e69929f78acbc53c7bfef3aba47b06d8981)), closes [GAT-7688](GAT-7688)
* **GAT-7711:** Implements ownership of CollectionHosts/Collections to Custodians. Also adds in basic auth and credential generation. ([8055326](https://github.com/HDRUK/project-daphne-api/commit/8055326e7c5a4e19e1a85e2a7bab15589242b86d)), closes [GAT-7711](GAT-7711)

### 🐛 Bug Fixes

* **DP-0001:** fix for running tests with authentication since standalone/integrated mode ([c5e9cd2](https://github.com/HDRUK/project-daphne-api/commit/c5e9cd247d9331efd3a8164dd1819c235a860527))
* **DP-0001:** Fixes broken integrated auth when attempting to decode jwt token ([b56e0c6](https://github.com/HDRUK/project-daphne-api/commit/b56e0c6e70514227502772c63c99b143b768de2d))
* **DP-117:** implement changes per comments (laravel-search-and-filter updates) and start refactoring to adhere to api standards missed ([7942219](https://github.com/HDRUK/project-daphne-api/commit/794221960fdb5f5c1a017a7a9331ded80487dccb)), closes [DP-117](DP-117)
* **DP-94-0000:** fixes random broken tests ([936693e](https://github.com/HDRUK/project-daphne-api/commit/936693e1dc2922379c2de9ef566d0414254c9e3a))
