# Iris ID 헤드리스 리뉴얼 — 콘텐츠 모델 & URL 인벤토리

> Phase 0 산출물. **소스 오브 트루스 = `irisid.com/wp-sitemap.xml` 크롤 (2026-06-25)**
> 전체 URL 원본: [`docs/migration/urls-pages.txt`](migration/urls-pages.txt) (123 pages), [`docs/migration/urls-posts.txt`](migration/urls-posts.txt) (396 posts)

---

## 0. 확정 아키텍처 (요약)

```
방문자 → Cloudflare(CDN·캐시·WAF·Turnstile)
          → InMotion VPS (irisid5, 12GB)
              ├─ Next.js App Router · SSR/ISR · PM2 (irisid.com)
              └─ 헤드리스 WordPress (cms.irisid.com)
                   ACF Pro · WPGraphQL · Gravity Forms · WP SMTP(Resend)
배포: GitHub Actions (iMac 러너) → SSH/rsync → pm2 reload
검색: Algolia (WP 저장 시 동기화)
```

---

## 1. 크롤로 확인된 핵심 사실 (중요)

1. **현재 사이트는 CPT가 전혀 없다.** 제품·솔루션·리소스가 전부 일반 `page`(Kubio)로 제작됨 → 사이트맵에 `post`/`page`/`category`/`post_tag`만 존재.
2. **SEO는 Yoast가 아니라 WP 코어 사이트맵**(`/wp-sitemap.xml`) 사용. (Yoast 도입은 리뉴얼 시 신규)
3. **GA4(MonsterInsights, G-7Z1L41153Y)** 사용 중 → 새 프론트에 이식 필요.
4. **페이지 123 + 글 396 = 총 519 URL.** 글 396개는 2003년 LG IrisAccess 시절부터의 뉴스/보도 아카이브.
5. 다량의 **캠페인/리드젠 랜딩 페이지**(whitepaper/webinar 다운로드 + Gravity Forms) 존재.
6. **중복/레거시 URL** 존재: `/support/rma-request/` vs `/support/rmarequest/`, `/licensing/` vs `/licensing-2/`, `/i-vs-f/` vs `/solutions/iris-recognition-vs-facial-authentication/` vs `/iris-vs-face/` 등 → 컷오버 시 canonical 1개로 정리 + 301.

---

## 2. URL 보존 원칙 (헤드리스 핵심)

- **프론트 URL은 Next.js 라우팅이 결정**한다. WordPress 퍼머링크는 데이터/프리뷰용일 뿐.
- 따라서 WP에는 **leaf slug만 보존**(예: `icam-d2000`)하고, Next가 `product_type + slug`로 전체 경로 `/products/hardware-products/icam-d2000/`를 재현 → **기존 URL 100% 동일**.
- 컷오버 전 위 519 URL을 Next 라우트와 **1:1 대조**. 누락/변경분만 301.
- 트레일링 슬래시(`/`) 정책: **현재대로 슬래시 유지**(`trailingSlash: true`).

---

## 3. 콘텐츠 타입 (CPT & 택소노미)

| CPT | graphql | URL 패턴 | 수량(현재) | 택소노미 |
|---|---|---|---|---|
| `product` | `product`/`products` | `/products/{type}/{slug}/` | HW 11, SW 7 | `product_type`(hardware-products, software-products), `solution_tax` |
| `solution` | `solution`/`solutions` | `/solutions/{slug}/` | 9 (+비교 1) | — |
| `resource` | `resource`/`resources` | `/resources/{type}/{slug}/` | 396 글 | `resource_type`(아래 10종) |
| `download` | `download`/`downloads` | (다운로드 센터/게이트) | 다수 | `download_type` |
| `faq` | `faq`/`faqs` | `/faq/` | 1 허브 | `faq_category` |
| 기본 `page` | `page`/`pages` | 임의 경로 | 나머지 | — (유연 콘텐츠) |

`resource_type` terms (실제 archive slug): `news-media`, `press-release`, `insights`, `events`, `videos`, `iris-id-talk`, `case-studies`, `webinars`, `literature`, `tip-sheets`.

모든 CPT/택소노미: `show_in_graphql = true`, leaf slug = 현재와 동일.

### 3-1. 실제 제품 목록 (URL 보존 대상)

**Hardware** `/products/hardware-products/{slug}/`
`irisaccess-ia1000`, `irisaccess-it100`, `ibar-600e`, `icam-d2000`, `icam-td200`, `icam-t10`, `icam-t20`, `icam-m300`, `icam-r100-series`, `icam-r200`, `ou500`

**Software** `/products/software-products/{slug}/`
`iams`, `itms`, `itms-cloud`, `idata-eac`, `idata-iris-sdk`, `irisaccelerator`, `irisaccelerator-m`

**단종(Discontinued)** — 개별 제품 상세 페이지 **없음**. `/discontinued/` 허브 1페이지만 유지(표 형태 목록). 아래 레거시 URL은 전부 **301 → `/discontinued/`** (§9.4).
`/discontinued/icam-d1000/`, `/discontinued/ou7s-ak/`, `/icu-7000-2/`, `/ou75/` 등

### 3-2. 실제 솔루션 목록 `/solutions/{slug}/`
`access-control`, `time-attendance`, `national-identity`, `data-centers`, `enterprise-and-corporate-campuses`, `law-enforcement`, `public-safety-justice`, `transportation-immigration`, `long-term-care-facilities`
비교 페이지: `iris-recognition-vs-facial-authentication` (→ `solution` 또는 `page`)

---

## 4. ACF 필드 스키마

### `product`
| 필드 | 타입 | 비고 |
|---|---|---|
| `subtitle` | Text | 모델 부제 |
| `short_description` | Textarea | 카드/메타 |
| `hero_image` | Image | |
| `gallery` | Gallery | |
| `status_badge` | Select | new / flagship / legacy (단종 모델은 `product` CPT에 **등록하지 않음** — §9.4 `discontinued_item`) |
| `key_features` | Repeater | icon, title, text |
| `specifications` | Repeater | spec_label, spec_value |
| `spec_sheet` | File | 사양서 PDF |
| `related_downloads` | Relationship → `download` | |
| `related_solutions` | Relationship → `solution` | |
| `related_products` | Relationship → `product` | |
| `video_url` | URL/oEmbed | |
| `cta` | Group | label, link |

### `solution`
| 필드 | 타입 |
|---|---|
| `headline` | Text |
| `intro` | WYSIWYG |
| `hero_image` | Image |
| `challenges` | Repeater (title, text) |
| `solution_body` | WYSIWYG |
| `stats` | Repeater (value, label) |
| `featured_products` | Relationship → `product` |
| `related_resources` | Relationship → `resource` |
| `cta` | Group (label, link) |

### `resource` (뉴스/보도/인사이트/이벤트/영상/웨비나/케이스스터디/리터러처/팁시트)
| 필드 | 타입 | 비고 |
|---|---|---|
| `excerpt` | Textarea | |
| `featured_image` | Image | |
| `body` | WYSIWYG | |
| `external_url` | URL | 외부 기사 링크 |
| `video_embed` | oEmbed | Videos/Iris ID Talk/Webinars |
| `attachment` | File | Literature/Tip Sheets PDF |
| `event_date` | Date | Events |
| `related_products` | Relationship → `product` | |
| `related_solutions` | Relationship → `solution` | |

### `download`
| 필드 | 타입 |
|---|---|
| `file` | File (필수) |
| `version` | Text |
| `os_platform` | Select |
| `thumbnail` | Image |
| `gated` | True/False |
| `gate_form_id` | Number (Gravity Forms) |
| `related_product` | Relationship → `product` |
| (분류) `download_type` | software / drivers / documentation / literature / tip-sheet |

### `faq`
| 필드 | 타입 |
|---|---|
| (질문) 글 제목 | — |
| `answer` | WYSIWYG |
| (분류) `faq_category` | — |
| `order` | Number |

### `page` — 유연 콘텐츠 섹션 블록
`hero`, `feature_grid`, `card_grid`(현 3카드 허브 재현), `text_media`, `product_showcase`, `solution_showcase`, `stats_band`, `logo_strip`(Our Customers), `testimonial`, `cta_band`, `faq_block`, `form_block`(GF id), `download_list`(download_type 선택)

---

## 5. Next.js 라우트 맵 (기존 URL 1:1)

| 라우트 | 데이터 소스 |
|---|---|
| `/` | `page` (home, 유연) |
| `/solutions/` | `page` (hub) |
| `/solutions/[slug]/` | `solution` |
| `/products/` | `page` (hub) |
| `/products/hardware-products/` | `product_type=hardware-products` archive |
| `/products/software-products/` | `product_type=software-products` archive |
| `/products/[type]/[slug]/` | `product` |
| `/resources/` | `page` (hub) |
| `/resources/[type]/` | `resource_type` archive |
| `/resources/[type]/[slug]/` | `resource` |
| `/support/` | `page` (hub) |
| `/support/product-registration/` | `page` + GF |
| `/support/rma-request/` | `page` + GF |
| `/support/software-upgrade-request/` | `page` + GF |
| `/support/software-drivers-documentation/` | `download` 목록 |
| `/company/` | `page` (hub) |
| `/company/about-iris-id/` | `page` |
| `/company/iris-recognition-technology/` | `page` (메뉴: Iris Recognition) |
| `/company/ai-powered-biometrics-technologies/` | `page` (메뉴: AI-Powered Biometrics) |
| `/company/our-customers/` | `page` (+ `/our-customers-world-map/`, `/sales-map/`) |
| `/company/careers/` | `page` |
| `/company/contact-us/` | `page` + GF |
| `/faq/`, `/privacy/`, `/legal/` | `page` / `faq` |
| `/discontinued/` | `page` (단종 목록 허브, §9.4) |
| (캠페인 랜딩 다수) | `page` + GF / `download` (§6) |

---

## 6. Gravity Forms 인벤토리 (헤드리스 제출 대상)

핵심 폼:
- **Contact Us** (`/company/contact-us/`)
- **Product Registration** (`/support/product-registration/`)
- **RMA Request** (`/support/rma-request/`, 레거시 `/support/rmarequest/`)
- **Software Upgrade Request** (`/support/software-upgrade-request/`)
- **License/Input License Request** (`/input-license-request/`, `/licensing/`)

리드젠(게이트 다운로드/웨비나) 폼 — 다수 랜딩 페이지:
- `*-whitepaper-download`, `*-pdf-page`, `*-form-page`, `*-webinar-form`, `watch-*-webinar`, `*-tip-sheet-download-page`, `newsletter`, `webinar-registration`, `survey_ts` 등

→ 처리: 각 폼은 Next에서 렌더 → `wp-graphql-gravity-forms` mutation 제출, **Cloudflare Turnstile** 스팸 방지, **Resend** 알림/자동응답. 게이트 다운로드는 폼 제출 성공 후 파일 URL 반환.

---

## 7. 레거시 / 리다이렉트 처리 (컷오버 시)

| 유형 | 예시 | 조치 |
|---|---|---|
| 중복 URL | `rma-request` vs `rmarequest`, `licensing` vs `licensing-2`, `i-vs-f`/`iris-vs-face`/`iris-recognition-vs-facial-authentication` | canonical 1개 + 나머지 301 |
| 단종 제품 | `/discontinued/*`, `/icu-7000-2/`, `/ou75/` 등 | **301 → `/discontinued/`** (개별 페이지 없음, §9.4) |
| 다국어(레거시) | `/en-espanol/`, `/korean/` | **301 → `/`** + locale 쿠키(§9.1). 별도 폴더 URL 없음 |
| 기능 페이지 | `/payment/`, `/my-bookings/`, `/thank-you/`, `/categories/`, `/tags/` | 필요성 검토 후 유지/제거 |
| 기술 페이지 | `/technologies/`, `/technologies/discover-iris-intelligence/`, `/technologies/standards-certificates/`, `/corporate-identity/`, `/howitworks/`, `/operationmodes/`, `/hardware-module/` | `page`로 이전 |

원칙: **삭제하는 URL은 반드시 301**. 유지 URL은 경로 동일.

---

## 8. 확정 의사결정 (2026-06-25)

| # | 항목 | 결정 |
|---|---|---|
| 1 | **다국어** | `/en-espanol/`, `/korean/` 같은 **별도 URL·별도 페이지 없음**. 동일 URL + **n8n 자동 번역** (§9.1) |
| 2 | **블로그/리소스** | **396개 전부 이전** → `resource` CPT |
| 3 | **게이트 다운로드** | **현행 유지** (§9.2) |
| 4 | **단종 제품** | **개별 상세 페이지 없음**. `/discontinued/` 허브 1페이지 + 레거시 URL **301** (§9.4) |
| 5 | **Knowledge Base** | **Salesforce 외부 링크 그대로** — WP/Next 콘텐츠 아님 (§9.3) |

---

## 9. 정책 상세

### 9.1 다국어 — n8n 방식 (폴더 URL ❌)

**원칙:** 모든 언어가 **같은 URL**을 공유한다. `/es/products/...` 같은 경로를 만들지 않는다.

```
[WP] 영문 마스터 저장 (ACF/본문)
        │
        ▼ webhook
   [n8n] 번역 워크플로 (DeepL/OpenAI 등)
        │
        ▼
   [WP] locale별 번역 메타 저장 (ACF Repeater 또는 별도 translation store)
        │
        ▼
   [Next] Accept-Language / 쿠키(NEXT_LOCALE) / 언어 스위처 → 해당 locale 렌더
```

- **마스터 언어:** English (기본)
- **지원 locale:** `en`, `es`, `ko` (추가 가능) — UI 스위처로 전환
- **WP 필드:** 각 CPT에 `translations` repeater — `locale`, `title`, `body`, ACF 필드 JSON 등 (또는 n8n → WP REST로 locale별 post meta)
- **캐시:** Next ISR 태그에 locale 포함 (`product:icam-d2000:ko`)
- **레거시:** `/en-espanol/`, `/korean/` → **301 `/`** (첫 방문 시 해당 locale 쿠키 설정 가능)
- **SEO:** `hreflang`은 **동일 canonical URL** + `link rel="alternate" hreflang="..."` (URL이 아닌 locale 메타로 처리) 또는 locale별 `<html lang="">` — 구현 단계에서 Yoast/consultant와 최종 확정

> n8n은 Phase 3~4에서 워크플로 구축. Phase 1~2는 **영문만**으로 사이트 완성 후 다국어 레이어 추가.

### 9.2 게이트 다운로드 — 유지 (이게 뭔지)

**게이트 다운로드** = PDF/백서/드라이버를 **바로 받지 못하고, 먼저 양식(이름·이메일·회사 등)을 제출한 뒤** 파일 링크를 받는 방식.

현재 사이트 예:
- `time-attendance-whitepaper-download` → 폼 제출 → PDF 페이지
- `5-tips-to-improve-long-term-care-facilities-pdf-page`
- Literature / Tip Sheets 일부

**리뉴얼 후 동작 (유지):**
1. 방문자가 다운로드 버튼 클릭
2. Gravity Forms(또는 게이트 전용 폼) 표시
3. 제출 성공 → Resend 확인메일 + **다운로드 URL** 제공(서명 URL 또는 thank-you 페이지)
4. `download` CPT의 `gated=true` + `gate_form_id`로 연결

마케팅 리드 수집용이므로 **그대로 유지**가 맞다.

### 9.3 Knowledge Base — Salesforce 외부

- SUPPORT 메뉴 **Knowledge Base** → **Salesforce URL 외부 링크** (새 탭 또는 동일 탭 정책 선택)
- WP `page` / Next 라우트 **생성하지 않음**
- irisid.com 도메인 아래 KB URL **없음** (기존에도 외부였을 가능성 높음 — 컷오버 시 메뉴만 Salesforce URL로 고정)

### 9.4 단종 제품 — 허브만, 개별 페이지 없음

**결정:** 단종 모델은 `/products/.../{slug}/` **상세 페이지를 만들지 않는다.**

**신규 구조:**
- **URL 1개:** `/discontinued/` — 단종 제품 **목록 페이지** (표: 모델명, 단종 안내, 대체 제품 링크(있을 경우))
- **데이터:** `page`의 ACF repeater `discontinued_items[]` — `model_name`, `notes`, `replacement_product`(relationship, optional), `discontinued_date`
- **`product` CPT:** 현行 18종(활성 제품)만. 단종 모델은 CPT에 넣지 않음

**301 리다이렉트 (컷오버 시 필수):**

| 기존 URL | → |
|---|---|
| `/discontinued/` | 유지 (목록 허브로 재구성) |
| `/discontinued/icam-d1000/` | 301 → `/discontinued/` |
| `/discontinued/ou7s-ak/` | 301 → `/discontinued/` |
| `/icu-7000-2/` | 301 → `/discontinued/` |
| `/ou75/` | 301 → `/discontinued/` |

→ Redirection 플러그인 또는 Next `redirects` + Cloudflare Rules. **삭제만 하고 301 없으면 SEO 404 손실.**

**301이란?** 브라우저/검색엔진에게 “이 주소는 영구적으로 이쪽으로 옮겼다”고 알려주는 리다이렉트. 구글 검색에 걸린 옛 URL도 새 목적지로 점수를 넘긴다.

---

## 부록: 페이지 카테고리 요약 (123 pages)

- 제품: 18 (HW 11 + SW 7) — **활성만**; 단종은 `/discontinued/` 허브 + 301
- 솔루션: 10 (+ 비교 1)
- 리소스 아카이브: 10 + 글 396
- 회사: 6
- 지원: 5
- 법무/FAQ: 3 (`faq`, `privacy`, `legal` + RMA 정책류)
- 캠페인/리드젠 랜딩: 30+ (whitepaper/webinar/tip-sheet)
- 기타 기능/기술/레거시 페이지: 나머지

전체 원본 목록은 [`migration/urls-pages.txt`](migration/urls-pages.txt) · [`migration/urls-posts.txt`](migration/urls-posts.txt) 참조.

---

## Phase 1 진행

헤드리스 WP 구축 가이드: **[PHASE-1-HEADLESS-WP.md](PHASE-1-HEADLESS-WP.md)**  
mu-plugin + ACF JSON: [`wordpress/`](../wordpress/)
