# Pilot — Product `icam-d2000` (첫 1개 완성 템플릿)

> live: https://irisid.com/products/hardware-products/icam-d2000/  
> cms: Products → Add New → slug **`icam-d2000`**

---

## 1. WordPress 기본

| 필드 | 값 |
|------|-----|
| Title | live와 동일 (예: iCAM D2000) |
| Slug | `icam-d2000` |
| Product Type | **Hardware** (`hardware`) |
| Status | Draft → 검증 후 Publish |

---

## 2. ACF — Product Fields

| ACF | GraphQL | 작업 |
|-----|---------|------|
| Subtitle | `subtitle` | live 헤드라인/서브타이틀 |
| Short Description | `shortDescription` | 상단 요약 2–3문장 |
| Hero Image | `heroImage` | Step 1 uploads 후 Media Library에서 선택 |
| Gallery | `gallery` | 제품 각도/설치 사진 |
| Status Badge | `statusBadge` | 필요 시 `new` / `flagship` |
| Key Features (repeater) | `keyFeatures` | title + description (+ icon) |
| Specifications (repeater) | `specifications` | label + value |
| Spec Sheet | `specSheet` | PDF Media Library |
| Related Products | `relatedProducts` | 같은 HW 라인 2–3개 (나중에 링크) |
| SEO | Yoast | title / meta (live `<title>` 참고) |

Kubio 블록 → repeater 행으로 **복사·붙여넣기**. HTML은 Plain text로 정리.

---

## 3. GraphiQL 검증

cms: **GraphQL → GraphiQL IDE**

```graphql
{
  product(id: "icam-d2000", idType: SLUG) {
    title
    slug
    productTypes {
      nodes {
        name
        slug
      }
    }
    productFields {
      subtitle
      shortDescription
      heroImage {
        sourceUrl
      }
      keyFeatures {
        title
        description
      }
    }
    seo {
      title
      metaDesc
    }
  }
}
```

**통과 조건:** slug `icam-d2000`, `productTypes`에 Hardware, hero + 3개 이상 keyFeatures.

---

## 4. 완료 후

- [ ] 이 체크리스트를 템플릿으로 **나머지 HW 10 + SW 7** 반복
- [`batch-products.txt`](batch-products.txt)에서 URL 하나씩 체크

---

## 5. 흔한 실수

- slug에 `products/` prefix 넣지 않기 (leaf만)
- Product Type taxonomy 누락 → Next URL `/products/hardware-products/...` 매핑 실패
- uploads 미동기화 → Hero/Gallery 이미지 404
