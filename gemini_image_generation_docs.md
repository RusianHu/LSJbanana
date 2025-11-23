# 使用 Gemini（又称 Nano Banana 🍌）生成图片

[源页面链接](https://ai.google.dev/gemini-api/docs/image-generation?hl=zh-cn)

Gemini 可以通过对话方式生成和处理图片。您可以使用文本、图片或两者结合来向 Gemini 发出提示，从而以前所未有的灵活度创建、修改和迭代视觉内容：

- Text-to-Image:：根据简单或复杂的文本描述生成高质量图片。
- 图片 + Text-to-Image（编辑）：提供图片，并使用文本提示添加、移除或修改元素、更改风格或调整色彩分级。
- 多图到图（合成和风格迁移）：使用多张输入图片合成新场景，或将一张图片的风格迁移到另一张图片。
- 迭代优化：通过对话在多轮互动中逐步优化图片，进行细微调整，直至达到理想效果。
- 高保真文本呈现：准确生成包含清晰易读且位置合理的文本的图片，非常适合用于徽标、图表和海报。

所有生成的图片都包含SynthID 水印。

本指南介绍了快速的 Gemini 2.5 Flash 和高级的 Gemini 3 Pro 预览版图片模型，并提供了从基本的文本转图片到复杂的多轮细化、4K 输出和基于搜索的生成等功能示例。

## 模型选择

选择最适合您的特定应用场景的模型。

- Gemini 3 Pro 图片预览版（Nano Banana Pro 预览版）专为专业素材资源制作和复杂指令而设计。该模型具有以下特点：使用 Google 搜索进行现实世界知识的接地、默认的“思考”过程（在生成之前优化构图），以及能够生成分辨率高达 4K 的图像。
- Gemini 2.5 Flash Image (Nano Banana)旨在提供快速高效的体验。此模型经过优化，可处理大批量、低延迟的任务，并生成 1024 像素分辨率的图片。

## 图片生成（根据文本生成图片）

以下代码演示了如何根据描述性提示生成图片。

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

prompt = (
    "Create a picture of a nano banana dish in a fancy restaurant with a Gemini theme"
)

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("generated_image.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "Create a picture of a nano banana dish in a fancy restaurant with a Gemini theme";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("gemini-native-image.png", buffer);
      console.log("Image saved as gemini-native-image.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
  "context"
  "fmt"
  "log"
  "os"
  "google.golang.org/genai"
)

func main() {

  ctx := context.Background()
  client, err := genai.NewClient(ctx, nil)
  if err != nil {
      log.Fatal(err)
  }

  result, _ := client.Models.GenerateContent(
      ctx,
      "gemini-2.5-flash-image",
      genai.Text("Create a picture of a nano banana dish in a " +
                 " fancy restaurant with a Gemini theme"),
  )

  for _, part := range result.Candidates[0].Content.Parts {
      if part.Text != "" {
          fmt.Println(part.Text)
      } else if part.InlineData != nil {
          imageBytes := part.InlineData.Data
          outputFilename := "gemini_generated_image.png"
          _ = os.WriteFile(outputFilename, imageBytes, 0644)
      }
  }
}

```

### Java

```java
import com.google.genai.Client;
import com.google.genai.types.GenerateContentConfig;
import com.google.genai.types.GenerateContentResponse;
import com.google.genai.types.Part;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Paths;

public class TextToImage {
  public static void main(String[] args) throws IOException {

    try (Client client = new Client()) {
      GenerateContentConfig config = GenerateContentConfig.builder()
          .responseModalities("TEXT", "IMAGE")
          .build();

      GenerateContentResponse response = client.models.generateContent(
          "gemini-3-pro-image-preview",
          "Create a picture of a nano banana dish in a fancy restaurant with a Gemini theme",
          config);

      for (Part part : response.parts()) {
        if (part.text().isPresent()) {
          System.out.println(part.text().get());
        } else if (part.inlineData().isPresent()) {
          var blob = part.inlineData().get();
          if (blob.data().isPresent()) {
            Files.write(Paths.get("_01_generated_image.png"), blob.data().get());
          }
        }
      }
    }
  }
}

```

### REST

```bash
curl -s -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "Create a picture of a nano banana dish in a fancy restaurant with a Gemini theme"}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > gemini-native-image.png

```

![AI 生成的迷你香蕉菜肴图片](images\nano-banana.png)
*AI 生成的图片：Gemini 主题餐厅中的 Nano Banana 菜肴*

## 图片修改（文字和图片转图片）

提醒：请确保您对上传的所有图片均拥有必要权利。
请勿生成会侵犯他人权利的内容，包括会欺骗、骚扰或伤害他人的视频或图片。使用此生成式 AI 服务时须遵守我们的《使用限制政策》。

以下示例演示了如何上传 base64 编码的图片。如需了解多张图片、更大的载荷和支持的 MIME 类型，请参阅图片理解页面。

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

prompt = (
    "Create a picture of my cat eating a nano-banana in a "
    "fancy restaurant under the Gemini constellation",
)

image = Image.open("/path/to/cat_image.png")

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt, image],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("generated_image.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const imagePath = "path/to/cat_image.png";
  const imageData = fs.readFileSync(imagePath);
  const base64Image = imageData.toString("base64");

  const prompt = [
    { text: "Create a picture of my cat eating a nano-banana in a" +
            "fancy restaurant under the Gemini constellation" },
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image,
      },
    },
  ];

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("gemini-native-image.png", buffer);
      console.log("Image saved as gemini-native-image.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
 "context"
 "fmt"
 "log"
 "os"
 "google.golang.org/genai"
)

func main() {

 ctx := context.Background()
 client, err := genai.NewClient(ctx, nil)
 if err != nil {
     log.Fatal(err)
 }

 imagePath := "/path/to/cat_image.png"
 imgData, _ := os.ReadFile(imagePath)

 parts := []*genai.Part{
   genai.NewPartFromText("Create a picture of my cat eating a nano-banana in a fancy restaurant under the Gemini constellation"),
   &genai.Part{
     InlineData: &genai.Blob{
       MIMEType: "image/png",
       Data:     imgData,
     },
   },
 }

 contents := []*genai.Content{
   genai.NewContentFromParts(parts, genai.RoleUser),
 }

 result, _ := client.Models.GenerateContent(
     ctx,
     "gemini-2.5-flash-image",
     contents,
 )

 for _, part := range result.Candidates[0].Content.Parts {
     if part.Text != "" {
         fmt.Println(part.Text)
     } else if part.InlineData != nil {
         imageBytes := part.InlineData.Data
         outputFilename := "gemini_generated_image.png"
         _ = os.WriteFile(outputFilename, imageBytes, 0644)
     }
 }
}

```

### Java

```java
import com.google.genai.Client;
import com.google.genai.types.Content;
import com.google.genai.types.GenerateContentConfig;
import com.google.genai.types.GenerateContentResponse;
import com.google.genai.types.Part;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;

public class TextAndImageToImage {
  public static void main(String[] args) throws IOException {

    try (Client client = new Client()) {
      GenerateContentConfig config = GenerateContentConfig.builder()
          .responseModalities("TEXT", "IMAGE")
          .build();

      GenerateContentResponse response = client.models.generateContent(
          "gemini-3-pro-image-preview",
          Content.fromParts(
              Part.fromText("""
                  Create a picture of my cat eating a nano-banana in
                  a fancy restaurant under the Gemini constellation
                  """),
              Part.fromBytes(
                  Files.readAllBytes(
                      Path.of("src/main/resources/cat.jpg")),
                  "image/jpeg")),
          config);

      for (Part part : response.parts()) {
        if (part.text().isPresent()) {
          System.out.println(part.text().get());
        } else if (part.inlineData().isPresent()) {
          var blob = part.inlineData().get();
          if (blob.data().isPresent()) {
            Files.write(Paths.get("gemini_generated_image.png"), blob.data().get());
          }
        }
      }
    }
  }
}

```

### REST

```bash
IMG_PATH=/path/to/cat_image.jpeg

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {\"text\": \"'Create a picture of my cat eating a nano-banana in a fancy restaurant under the Gemini constellation\"},
            {
              \"inline_data\": {
                \"mime_type\":\"image/jpeg\",
                \"data\": \"$IMG_BASE64\"
              }
            }
        ]
      }]
    }"  \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > gemini-edited-image.png

```

![AI 生成的猫吃香蕉的图片](images\cat-banana.png)
*AI 生成的猫吃迷你香蕉的图片*

### 多轮图片修改

继续以对话方式生成和修改图片。建议通过聊天或多轮对话来迭代生成图片。以下示例展示了生成有关光合作用的信息图表的提示。

### Python

```python
from google import genai
from google.genai import types

client = genai.Client()

chat = client.chats.create(
    model="gemini-3-pro-image-preview",
    config=types.GenerateContentConfig(
        response_modalities=['TEXT', 'IMAGE'],
        tools=[{"google_search": {}}]
    )
)

message = "Create a vibrant infographic that explains photosynthesis as if it were a recipe for a plant's favorite food. Show the \"ingredients\" (sunlight, water, CO2) and the \"finished dish\" (sugar/energy). The style should be like a page from a colorful kids' cookbook, suitable for a 4th grader."

response = chat.send_message(message)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif image:= part.as_image():
        image.save("photosynthesis.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";

const ai = new GoogleGenAI({});

async function main() {
  const chat = ai.chats.create({
    model: "gemini-3-pro-image-preview",
    config: {
      responseModalities: ['TEXT', 'IMAGE'],
      tools: [{googleSearch: {}}],
    },
  });

await main();

const message = "Create a vibrant infographic that explains photosynthesis as if it were a recipe for a plant's favorite food. Show the \"ingredients\" (sunlight, water, CO2) and the \"finished dish\" (sugar/energy). The style should be like a page from a colorful kids' cookbook, suitable for a 4th grader."

let response = await chat.sendMessage({message});

for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("photosynthesis.png", buffer);
      console.log("Image saved as photosynthesis.png");
    }
}

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"

    "google.golang.org/genai"
)

func main() {
    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }
    defer client.Close()

    model := client.GenerativeModel("gemini-3-pro-image-preview")
    model.GenerationConfig = &pb.GenerationConfig{
        ResponseModalities: []pb.ResponseModality{genai.Text, genai.Image},
    }
    chat := model.StartChat()

    message := "Create a vibrant infographic that explains photosynthesis as if it were a recipe for a plant's favorite food. Show the \"ingredients\" (sunlight, water, CO2) and the \"finished dish\" (sugar/energy). The style should be like a page from a colorful kids' cookbook, suitable for a 4th grader."

    resp, err := chat.SendMessage(ctx, genai.Text(message))
    if err != nil {
        log.Fatal(err)
    }

    for _, part := range resp.Candidates[0].Content.Parts {
        if txt, ok := part.(genai.Text); ok {
            fmt.Printf("%s", string(txt))
        } else if img, ok := part.(genai.ImageData); ok {
            err := os.WriteFile("photosynthesis.png", img.Data, 0644)
            if err != nil {
                log.Fatal(err)
            }
        }
    }
}

```

### Java

```java
import com.google.genai.Chat;
import com.google.genai.Client;
import com.google.genai.types.Content;
import com.google.genai.types.GenerateContentConfig;
import com.google.genai.types.GenerateContentResponse;
import com.google.genai.types.GoogleSearch;
import com.google.genai.types.ImageConfig;
import com.google.genai.types.Part;
import com.google.genai.types.RetrievalConfig;
import com.google.genai.types.Tool;
import com.google.genai.types.ToolConfig;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;

public class MultiturnImageEditing {
  public static void main(String[] args) throws IOException {

    try (Client client = new Client()) {

      GenerateContentConfig config = GenerateContentConfig.builder()
          .responseModalities("TEXT", "IMAGE")
          .tools(Tool.builder()
              .googleSearch(GoogleSearch.builder().build())
              .build())
          .build();

      Chat chat = client.chats.create("gemini-3-pro-image-preview", config);

      GenerateContentResponse response = chat.sendMessage("""
          Create a vibrant infographic that explains photosynthesis
          as if it were a recipe for a plant's favorite food.
          Show the "ingredients" (sunlight, water, CO2)
          and the "finished dish" (sugar/energy).
          The style should be like a page from a colorful
          kids' cookbook, suitable for a 4th grader.
          """);

      for (Part part : response.parts()) {
        if (part.text().isPresent()) {
          System.out.println(part.text().get());
        } else if (part.inlineData().isPresent()) {
          var blob = part.inlineData().get();
          if (blob.data().isPresent()) {
            Files.write(Paths.get("photosynthesis.png"), blob.data().get());
          }
        }
      }
      // ...
    }
  }
}

```

### REST

```bash
curl -s -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "role": "user",
      "parts": [
        {"text": "Create a vibrant infographic that explains photosynthesis as if it were a recipe for a plants favorite food. Show the \"ingredients\" (sunlight, water, CO2) and the \"finished dish\" (sugar/energy). The style should be like a page from a colorful kids cookbook, suitable for a 4th grader."}
      ]
    }],
    "generationConfig": {
      "responseModalities": ["TEXT", "IMAGE"]
    }
  }' > turn1_response.json

cat turn1_response.json
# Requires jq to parse JSON response
jq -r '.candidates[0].content.parts[] | select(.inlineData) | .inlineData.data' turn1_response.json | head -1 | base64 --decode > photosynthesis.png

```

![关于光合作用的 AI 生成的信息图](images\infographic-eng.png)
*AI 生成的有关光合作用的信息图*

然后，您可以使用同一对话将图片中的文字更改为西班牙语。

### Python

```python
message = "Update this infographic to be in Spanish. Do not change any other elements of the image."
aspect_ratio = "16:9" # "1:1","2:3","3:2","3:4","4:3","4:5","5:4","9:16","16:9","21:9"
resolution = "2K" # "1K", "2K", "4K"

response = chat.send_message(message,
    config=types.GenerateContentConfig(
        image_config=types.ImageConfig(
            aspect_ratio=aspect_ratio,
            image_size=resolution
        ),
    ))

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif image:= part.as_image():
        image.save("photosynthesis_spanish.png")

```

### JavaScript

```javascript
const message = 'Update this infographic to be in Spanish. Do not change any other elements of the image.';
const aspectRatio = '16:9';
const resolution = '2K';

let response = await chat.sendMessage({
  message,
  config: {
    responseModalities: ['TEXT', 'IMAGE'],
    imageConfig: {
      aspectRatio: aspectRatio,
      imageSize: resolution,
    },
    tools: [{googleSearch: {}}],
  },
});

for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("photosynthesis2.png", buffer);
      console.log("Image saved as photosynthesis2.png");
    }
}

```

### Go

```go
message = "Update this infographic to be in Spanish. Do not change any other elements of the image."
aspect_ratio = "16:9" // "1:1","2:3","3:2","3:4","4:3","4:5","5:4","9:16","16:9","21:9"
resolution = "2K"     // "1K", "2K", "4K"

model.GenerationConfig.ImageConfig = &pb.ImageConfig{
    AspectRatio: aspect_ratio,
    ImageSize:   resolution,
}

resp, err = chat.SendMessage(ctx, genai.Text(message))
if err != nil {
    log.Fatal(err)
}

for _, part := range resp.Candidates[0].Content.Parts {
    if txt, ok := part.(genai.Text); ok {
        fmt.Printf("%s", string(txt))
    } else if img, ok := part.(genai.ImageData); ok {
        err := os.WriteFile("photosynthesis_spanish.png", img.Data, 0644)
        if err != nil {
            log.Fatal(err)
        }
    }
}

```

### Java

```java
String aspectRatio = "16:9"; // "1:1","2:3","3:2","3:4","4:3","4:5","5:4","9:16","16:9","21:9"
String resolution = "2K"; // "1K", "2K", "4K"

config = GenerateContentConfig.builder()
    .responseModalities("TEXT", "IMAGE")
    .imageConfig(ImageConfig.builder()
        .aspectRatio(aspectRatio)
        .imageSize(resolution)
        .build())
    .build();

response = chat.sendMessage(
    "Update this infographic to be in Spanish. " + 
    "Do not change any other elements of the image.",
    config);

for (Part part : response.parts()) {
  if (part.text().isPresent()) {
    System.out.println(part.text().get());
  } else if (part.inlineData().isPresent()) {
    var blob = part.inlineData().get();
    if (blob.data().isPresent()) {
      Files.write(Paths.get("photosynthesis_spanish.png"), blob.data().get());
    }
  }
}

```

### REST

```bash
# Create request2.json by combining history and new prompt
# Read model's previous response content directly into jq
jq --argjson user1 '{"role": "user", "parts": [{"text": "Create a vibrant infographic that explains photosynthesis as if it were a recipe for a plant'\''s favorite food. Show the \"ingredients\" (sunlight, water, CO2) and the \"finished dish\" (sugar/energy). The style should be like a page from a colorful kids'\'' cookbook, suitable for a 4th grader."}]}' \
  --argjson user2 '{"role": "user", "parts": [{"text": "Update this infographic to be in Spanish. Do not change any other elements of the image."}]}' \
  -f /dev/stdin turn1_response.json > request2.json <<'EOF_JQ_FILTER'
.candidates[0].content | {
  "contents": [$user1, ., $user2],
  "tools": [{"google_search": {}}],
  "generationConfig": {
    "responseModalities": ["TEXT", "IMAGE"],
    "imageConfig": {
      "aspectRatio": "16:9",
      "imageSize": "2K"
    }
  }
}
EOF_JQ_FILTER

curl -s -X POST \
"https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
-H "Content-Type: application/json" \
-d @request2.json > turn2_response.json

jq -r '.candidates[0].content.parts[] | select(.inlineData) | .inlineData.data' turn2_response.json | head -1 | base64 --decode > photosynthesis_spanish.png

```

![AI 生成的西班牙语光合作用信息图](images\infographic-spanish.png)
*AI 生成的西班牙语光合作用信息图*

## Gemini 3 Pro 图片功能的新变化

Gemini 3 Pro Image (gemini-3-pro-image-preview) 是一款最先进的图像生成与编辑模型，专为专业素材资源制作而优化。
Gemini 1.5 Pro 旨在通过高级推理功能应对最具挑战性的工作流程，擅长处理复杂的、多轮的创建和修改任务。

- 高分辨率输出：内置了生成 1K、2K 和 4K 视觉内容的功能。
- 高级文字渲染：能够为信息图表、菜单、图表和营销素材资源生成清晰易读的风格化文字。
- 使用 Google 搜索进行接地：模型可以使用 Google 搜索作为工具来验证事实，并根据实时数据（例如当前天气地图、股市图表、近期活动）生成图片。
- 思考模式：模型会利用“思考”过程来推理复杂的提示。它会生成临时“思维图像”（在后端可见，但不收费），以在生成最终的高质量输出之前优化构图。
- 最多 14 张参考图片：您现在最多可以混合使用 14 张参考图片来生成最终图片。

### 最多可使用 14 张参考图片

借助 Gemini 3 Pro 预览版，您可以混合使用最多 14 张参考图片。这 14 张图片可以包含以下内容：

- 最多 6 张高保真对象图片，用于包含在最终图片中
- 最多 5 张人像照片，以保持角色一致性

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

prompt = "An office group photo of these people, they are making funny faces."
aspect_ratio = "5:4" # "1:1","2:3","3:2","3:4","4:3","4:5","5:4","9:16","16:9","21:9"
resolution = "2K" # "1K", "2K", "4K"

client = genai.Client()

response = client.models.generate_content(
    model="gemini-3-pro-image-preview",
    contents=[
        prompt,
        Image.open('person1.png'),
        Image.open('person2.png'),
        Image.open('person3.png'),
        Image.open('person4.png'),
        Image.open('person5.png'),
    ],
    config=types.GenerateContentConfig(
        response_modalities=['TEXT', 'IMAGE'],
        image_config=types.ImageConfig(
            aspect_ratio=aspect_ratio,
            image_size=resolution
        ),
    )
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif image:= part.as_image():
        image.save("office.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
      'An office group photo of these people, they are making funny faces.';
  const aspectRatio = '5:4';
  const resolution = '2K';

const contents = [
  { text: prompt },
  {
    inlineData: {
      mimeType: "image/jpeg",
      data: base64ImageFile1,
    },
  },
  {
    inlineData: {
      mimeType: "image/jpeg",
      data: base64ImageFile2,
    },
  },
  {
    inlineData: {
      mimeType: "image/jpeg",
      data: base64ImageFile3,
    },
  },
  {
    inlineData: {
      mimeType: "image/jpeg",
      data: base64ImageFile4,
    },
  },
  {
    inlineData: {
      mimeType: "image/jpeg",
      data: base64ImageFile5,
    },
  }
];

const response = await ai.models.generateContent({
    model: 'gemini-3-pro-image-preview',
    contents: contents,
    config: {
      responseModalities: ['TEXT', 'IMAGE'],
      imageConfig: {
        aspectRatio: aspectRatio,
        imageSize: resolution,
      },
    },
  });

  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("image.png", buffer);
      console.log("Image saved as image.png");
    }
  }

}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"

    "google.golang.org/genai"
)

func main() {
    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }
    defer client.Close()

    model := client.GenerativeModel("gemini-3-pro-image-preview")
    model.GenerationConfig = &pb.GenerationConfig{
        ResponseModalities: []pb.ResponseModality{genai.Text, genai.Image},
        ImageConfig: &pb.ImageConfig{
            AspectRatio: "5:4",
            ImageSize:   "2K",
        },
    }

    img1, err := os.ReadFile("person1.png")
    if err != nil { log.Fatal(err) }
    img2, err := os.ReadFile("person2.png")
    if err != nil { log.Fatal(err) }
    img3, err := os.ReadFile("person3.png")
    if err != nil { log.Fatal(err) }
    img4, err := os.ReadFile("person4.png")
    if err != nil { log.Fatal(err) }
    img5, err := os.ReadFile("person5.png")
    if err != nil { log.Fatal(err) }

    parts := []genai.Part{
        genai.Text("An office group photo of these people, they are making funny faces."),
        genai.ImageData{MIMEType: "image/png", Data: img1},
        genai.ImageData{MIMEType: "image/png", Data: img2},
        genai.ImageData{MIMEType: "image/png", Data: img3},
        genai.ImageData{MIMEType: "image/png", Data: img4},
        genai.ImageData{MIMEType: "image/png", Data: img5},
    }

    resp, err := model.GenerateContent(ctx, parts...)
    if err != nil {
        log.Fatal(err)
    }

    for _, part := range resp.Candidates[0].Content.Parts {
        if txt, ok := part.(genai.Text); ok {
            fmt.Printf("%s", string(txt))
        } else if img, ok := part.(genai.ImageData); ok {
            err := os.WriteFile("office.png", img.Data, 0644)
            if err != nil {
                log.Fatal(err)
            }
        }
    }
}

```

### Java

```java
import com.google.genai.Client;
import com.google.genai.types.Content;
import com.google.genai.types.GenerateContentConfig;
import com.google.genai.types.GenerateContentResponse;
import com.google.genai.types.ImageConfig;
import com.google.genai.types.Part;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;

public class GroupPhoto {
  public static void main(String[] args) throws IOException {

    try (Client client = new Client()) {
      GenerateContentConfig config = GenerateContentConfig.builder()
          .responseModalities("TEXT", "IMAGE")
          .imageConfig(ImageConfig.builder()
              .aspectRatio("5:4")
              .imageSize("2K")
              .build())
          .build();

      GenerateContentResponse response = client.models.generateContent(
          "gemini-3-pro-image-preview",
          Content.fromParts(
              Part.fromText("An office group photo of these people, they are making funny faces."),
              Part.fromBytes(Files.readAllBytes(Path.of("person1.png")), "image/png"),
              Part.fromBytes(Files.readAllBytes(Path.of("person2.png")), "image/png"),
              Part.fromBytes(Files.readAllBytes(Path.of("person3.png")), "image/png"),
              Part.fromBytes(Files.readAllBytes(Path.of("person4.png")), "image/png"),
              Part.fromBytes(Files.readAllBytes(Path.of("person5.png")), "image/png")
          ), config);

      for (Part part : response.parts()) {
        if (part.text().isPresent()) {
          System.out.println(part.text().get());
        } else if (part.inlineData().isPresent()) {
          var blob = part.inlineData().get();
          if (blob.data().isPresent()) {
            Files.write(Paths.get("office.png"), blob.data().get());
          }
        }
      }
    }
  }
}

```

### REST

```bash
IMG_PATH1=person1.png
IMG_PATH2=person2.png
IMG_PATH3=person3.png
IMG_PATH4=person4.png
IMG_PATH5=person5.png

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG1_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH1" 2>&1)
IMG2_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH2" 2>&1)
IMG3_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH3" 2>&1)
IMG4_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH4" 2>&1)
IMG5_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH5" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {\"text\": \"An office group photo of these people, they are making funny faces.\"},
            {\"inline_data\": {\"mime_type\":\"image/png\", \"data\": \"$IMG1_BASE64\"}},
            {\"inline_data\": {\"mime_type\":\"image/png\", \"data\": \"$IMG2_BASE64\"}},
            {\"inline_data\": {\"mime_type\":\"image/png\", \"data\": \"$IMG3_BASE64\"}},
            {\"inline_data\": {\"mime_type\":\"image/png\", \"data\": \"$IMG4_BASE64\"}},
            {\"inline_data\": {\"mime_type\":\"image/png\", \"data\": \"$IMG5_BASE64\"}}
        ]
      }],
      \"generationConfig\": {
        \"responseModalities\": [\"TEXT\", \"IMAGE\"],
        \"imageConfig\": {
          \"aspectRatio\": \"5:4\",
          \"imageSize\": \"2K\"
        }
      }
    }" | jq -r '.candidates[0].content.parts[] | select(.inlineData) | .inlineData.data' | head -1 | base64 --decode > office.png

```

![AI 生成的办公室合影](images\office-group-photo.jpeg)
*AI 生成的办公室合影*

### 使用 Google 搜索建立依据

使用Google 搜索工具根据实时信息（例如天气预报、股市图表或近期活动）生成图片。

将“依托 Google 搜索进行接地”与图片生成功能搭配使用时，需要注意以下事项：

- 基于图片的搜索结果不会传递给生成模型，并且会从回答中排除。
- 图片专用模式 (responseModalities = ["IMAGE"]) 与“依托 Google 搜索进行接地”搭配使用时，不会返回图片输出。

### Python

```python
from google import genai
prompt = "Visualize the current weather forecast for the next 5 days in San Francisco as a clean, modern weather chart. Add a visual on what I should wear each day"
aspect_ratio = "16:9" # "1:1","2:3","3:2","3:4","4:3","4:5","5:4","9:16","16:9","21:9"

client = genai.Client()

response = client.models.generate_content(
    model="gemini-3-pro-image-preview",
    contents=prompt,
    config=types.GenerateContentConfig(
        response_modalities=['Text', 'Image'],
        image_config=types.ImageConfig(
            aspect_ratio=aspect_ratio,
        ),
        tools=[{"google_search": {}}]
    )
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif image:= part.as_image():
        image.save("weather.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt = 'Visualize the current weather forecast for the next 5 days in San Francisco as a clean, modern weather chart. Add a visual on what I should wear each day';
  const aspectRatio = '16:9';
  const resolution = '2K';

const response = await ai.models.generateContent({
    model: 'gemini-3-pro-image-preview',
    contents: prompt,
    config: {
      responseModalities: ['TEXT', 'IMAGE'],
      imageConfig: {
        aspectRatio: aspectRatio,
        imageSize: resolution,
      },
    },
  });

  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("image.png", buffer);
      console.log("Image saved as image.png");
    }
  }

}

main();


```

### Java

```java
import com.google.genai.Client;
import com.google.genai.types.GenerateContentConfig;
import com.google.genai.types.GenerateContentResponse;
import com.google.genai.types.GoogleSearch;
import com.google.genai.types.ImageConfig;
import com.google.genai.types.Part;
import com.google.genai.types.Tool;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Paths;

public class SearchGrounding {
  public static void main(String[] args) throws IOException {

    try (Client client = new Client()) {
      GenerateContentConfig config = GenerateContentConfig.builder()
          .responseModalities("TEXT", "IMAGE")
          .imageConfig(ImageConfig.builder()
              .aspectRatio("16:9")
              .build())
          .tools(Tool.builder()
              .googleSearch(GoogleSearch.builder().build())
              .build())
          .build();

      GenerateContentResponse response = client.models.generateContent(
          "gemini-3-pro-image-preview", """
              Visualize the current weather forecast for the next 5 days 
              in San Francisco as a clean, modern weather chart. 
              Add a visual on what I should wear each day
              """,
          config);

      for (Part part : response.parts()) {
        if (part.text().isPresent()) {
          System.out.println(part.text().get());
        } else if (part.inlineData().isPresent()) {
          var blob = part.inlineData().get();
          if (blob.data().isPresent()) {
            Files.write(Paths.get("weather.png"), blob.data().get());
          }
        }
      }
    }
  }
}

```

### REST

```bash
curl -s -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{"parts": [{"text": "Visualize the current weather forecast for the next 5 days in San Francisco as a clean, modern weather chart. Add a visual on what I should wear each day"}]}],
    "tools": [{"google_search": {}}],
    "generationConfig": {
      "responseModalities": ["TEXT", "IMAGE"],
      "imageConfig": {"aspectRatio": "16:9"}
    }
  }' | jq -r '.candidates[0].content.parts[] | select(.inlineData) | .inlineData.data' | head -1 | base64 --decode > weather.png

```

![AI 生成的旧金山五天天气图表](images\weather-forecast.png)
*旧金山未来五天的天气图表（由 AI 生成）*

响应包含groundingMetadata，其中包含以下必需字段：

- searchEntryPoint：包含用于呈现所需搜索建议的 HTML 和 CSS。
- groundingChunks：返回用于为生成的图片提供依据的前 3 个网络来源

### 生成分辨率高达 4K 的图片

Gemini 3 Pro Image 默认生成 1K 图片，但也可以输出 2K 和 4K 图片。如需生成更高分辨率的资源，请在generation_config中指定image_size。

您必须使用大写“K”（例如，1K、2K、4K）。小写参数（例如，1k）将被拒绝。

### Python

```python
from google import genai
from google.genai import types

prompt = "Da Vinci style anatomical sketch of a dissected Monarch butterfly. Detailed drawings of the head, wings, and legs on textured parchment with notes in English." 
aspect_ratio = "1:1" # "1:1","2:3","3:2","3:4","4:3","4:5","5:4","9:16","16:9","21:9"
resolution = "1K" # "1K", "2K", "4K"

client = genai.Client()

response = client.models.generate_content(
    model="gemini-3-pro-image-preview",
    contents=prompt,
    config=types.GenerateContentConfig(
        response_modalities=['TEXT', 'IMAGE'],
        image_config=types.ImageConfig(
            aspect_ratio=aspect_ratio,
            image_size=resolution
        ),
    )
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif image:= part.as_image():
        image.save("butterfly.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
      'Da Vinci style anatomical sketch of a dissected Monarch butterfly. Detailed drawings of the head, wings, and legs on textured parchment with notes in English.';
  const aspectRatio = '1:1';
  const resolution = '1K';

  const response = await ai.models.generateContent({
    model: 'gemini-3-pro-image-preview',
    contents: prompt,
    config: {
      responseModalities: ['TEXT', 'IMAGE'],
      imageConfig: {
        aspectRatio: aspectRatio,
        imageSize: resolution,
      },
    },
  });

  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("image.png", buffer);
      console.log("Image saved as image.png");
    }
  }

}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"

    "google.golang.org/genai"
)

func main() {
    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }
    defer client.Close()

    model := client.GenerativeModel("gemini-3-pro-image-preview")
    model.GenerationConfig = &pb.GenerationConfig{
        ResponseModalities: []pb.ResponseModality{genai.Text, genai.Image},
        ImageConfig: &pb.ImageConfig{
            AspectRatio: "1:1",
            ImageSize:   "1K",
        },
    }

    prompt := "Da Vinci style anatomical sketch of a dissected Monarch butterfly. Detailed drawings of the head, wings, and legs on textured parchment with notes in English."
    resp, err := model.GenerateContent(ctx, genai.Text(prompt))
    if err != nil {
        log.Fatal(err)
    }

    for _, part := range resp.Candidates[0].Content.Parts {
        if txt, ok := part.(genai.Text); ok {
            fmt.Printf("%s", string(txt))
        } else if img, ok := part.(genai.ImageData); ok {
            err := os.WriteFile("butterfly.png", img.Data, 0644)
            if err != nil {
                log.Fatal(err)
            }
        }
    }
}

```

### Java

```java
import com.google.genai.Client;
import com.google.genai.types.GenerateContentConfig;
import com.google.genai.types.GenerateContentResponse;
import com.google.genai.types.GoogleSearch;
import com.google.genai.types.ImageConfig;
import com.google.genai.types.Part;
import com.google.genai.types.Tool;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Paths;

public class HiRes {
    public static void main(String[] args) throws IOException {

      try (Client client = new Client()) {
        GenerateContentConfig config = GenerateContentConfig.builder()
            .responseModalities("TEXT", "IMAGE")
            .imageConfig(ImageConfig.builder()
                .aspectRatio("16:9")
                .imageSize("4K")
                .build())
            .build();

        GenerateContentResponse response = client.models.generateContent(
            "gemini-3-pro-image-preview", """
              Da Vinci style anatomical sketch of a dissected Monarch butterfly.
              Detailed drawings of the head, wings, and legs on textured
              parchment with notes in English.
              """,
            config);

        for (Part part : response.parts()) {
          if (part.text().isPresent()) {
            System.out.println(part.text().get());
          } else if (part.inlineData().isPresent()) {
            var blob = part.inlineData().get();
            if (blob.data().isPresent()) {
              Files.write(Paths.get("butterfly.png"), blob.data().get());
            }
          }
        }
      }
    }
}

```

### REST

```bash
curl -s -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{"parts": [{"text": "Da Vinci style anatomical sketch of a dissected Monarch butterfly. Detailed drawings of the head, wings, and legs on textured parchment with notes in English."}]}],
    "tools": [{"google_search": {}}],
    "generationConfig": {
      "responseModalities": ["TEXT", "IMAGE"],
      "imageConfig": {"aspectRatio": "1:1", "imageSize": "1K"}
    }
  }' | jq -r '.candidates[0].content.parts[] | select(.inlineData) | .inlineData.data' | head -1 | base64 --decode > butterfly.png

```

以下是根据此提示生成的示例图片：

![AI 生成的解剖帝王蝶的达芬奇风格解剖草图。](images\gemini3-4k-image.png)
*AI 生成的达芬奇风格的解剖君主斑蝶的解剖草图。*

### 思考过程

Gemini 3 Pro Image Preview 模型是一个思考型模型，会使用推理流程（“思考”）来处理复杂的提示。此功能默认处于启用状态，并且无法在 API 中停用。如需详细了解思考过程，请参阅Gemini 思考指南。

模型最多会生成两张临时图片，以测试构图和逻辑。“思考”中的最后一张图片也是最终渲染的图片。

您可以查看促成最终图片生成的想法。

### Python

```python
for part in response.parts:
    if part.thought:
        if part.text:
            print(part.text)
        elif image:= part.as_image():
            image.show()

```

### JavaScript

```javascript
for (const part of response.candidates[0].content.parts) {
  if (part.thought) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, 'base64');
      fs.writeFileSync('image.png', buffer);
      console.log('Image saved as image.png');
    }
  }
}

```

#### 思路签名

思考特征是模型内部思考过程的加密表示形式，用于在多轮互动中保留推理上下文。所有响应都包含thought_signature字段。一般来说，如果您在模型响应中收到思考特征，则应在下一轮对话中发送对话历史记录时，按原样将其传递回去。未能循环使用思维签名可能会导致回答失败。如需详细了解签名，请参阅思想签名文档。

以下是意念特征的运作方式：

- 所有包含图片mimetype的inline_data部分（属于响应的一部分）都应具有签名。
- 如果想法之后（在任何图片之前）紧跟着一些文字部分，则第一个文字部分也应包含签名。
- 想法没有签名；如果带有图片mimetype的inline_data部分是想法的一部分，则不会有签名。

以下代码展示了包含思维签名的示例：

## 其他图片生成模式

Gemini 还支持其他基于提示结构和上下文的图片互动模式，包括：

- 文生图和文本（交织）：输出包含相关文本的图片。提示示例：“生成一份图文并茂的海鲜饭食谱。”
- 图片和文本转图片和文本（交织）：使用输入图片和文本创建新的相关图片和文本。提示示例：（附带一张带家具的房间的照片）“我的空间还适合放置哪些颜色的沙发？你能更新一下图片吗？”

## 提示指南和策略

掌握图片生成技术首先要遵循一个基本原则：

### 用于生成图片的提示

以下策略将帮助您创建有效的提示，从而生成您想要的图片。

#### 1. 逼真场景

对于逼真的图片，请使用摄影术语。提及拍摄角度、镜头类型、光线和细节，引导模型生成逼真的效果。

### 模板

```
A photorealistic [shot type] of [subject], [action or expression], set in
[environment]. The scene is illuminated by [lighting description], creating
a [mood] atmosphere. Captured with a [camera/lens details], emphasizing
[key textures and details]. The image should be in a [aspect ratio] format.

```

### 提示

```
A photorealistic close-up portrait of an elderly Japanese ceramicist with
deep, sun-etched wrinkles and a warm, knowing smile. He is carefully
inspecting a freshly glazed tea bowl. The setting is his rustic,
sun-drenched workshop. The scene is illuminated by soft, golden hour light
streaming through a window, highlighting the fine texture of the clay.
Captured with an 85mm portrait lens, resulting in a soft, blurred background
(bokeh). The overall mood is serene and masterful. Vertical portrait
orientation.

```

### Python

```python
from google import genai
from google.genai import types    

client = genai.Client()

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents="A photorealistic close-up portrait of an elderly Japanese ceramicist with deep, sun-etched wrinkles and a warm, knowing smile. He is carefully inspecting a freshly glazed tea bowl. The setting is his rustic, sun-drenched workshop with pottery wheels and shelves of clay pots in the background. The scene is illuminated by soft, golden hour light streaming through a window, highlighting the fine texture of the clay and the fabric of his apron. Captured with an 85mm portrait lens, resulting in a soft, blurred background (bokeh). The overall mood is serene and masterful.",
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("photorealistic_example.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "A photorealistic close-up portrait of an elderly Japanese ceramicist with deep, sun-etched wrinkles and a warm, knowing smile. He is carefully inspecting a freshly glazed tea bowl. The setting is his rustic, sun-drenched workshop with pottery wheels and shelves of clay pots in the background. The scene is illuminated by soft, golden hour light streaming through a window, highlighting the fine texture of the clay and the fabric of his apron. Captured with an 85mm portrait lens, resulting in a soft, blurred background (bokeh). The overall mood is serene and masterful.";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("photorealistic_example.png", buffer);
      console.log("Image saved as photorealistic_example.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "google.golang.org/genai"
)

func main() {

    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }

    result, _ := client.Models.GenerateContent(
        ctx,
        "gemini-2.5-flash-image",
        genai.Text("A photorealistic close-up portrait of an elderly Japanese ceramicist with deep, sun-etched wrinkles and a warm, knowing smile. He is carefully inspecting a freshly glazed tea bowl. The setting is his rustic, sun-drenched workshop with pottery wheels and shelves of clay pots in the background. The scene is illuminated by soft, golden hour light streaming through a window, highlighting the fine texture of the clay and the fabric of his apron. Captured with an 85mm portrait lens, resulting in a soft, blurred background (bokeh). The overall mood is serene and masterful."),
    )

    for _, part := range result.Candidates[0].Content.Parts {
        if part.Text != "" {
            fmt.Println(part.Text)
        } else if part.InlineData != nil {
            imageBytes := part.InlineData.Data
            outputFilename := "photorealistic_example.png"
            _ = os.WriteFile(outputFilename, imageBytes, 0644)
        }
    }
}

```

### REST

```bash
curl -s -X POST
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "A photorealistic close-up portrait of an elderly Japanese ceramicist with deep, sun-etched wrinkles and a warm, knowing smile. He is carefully inspecting a freshly glazed tea bowl. The setting is his rustic, sun-drenched workshop with pottery wheels and shelves of clay pots in the background. The scene is illuminated by soft, golden hour light streaming through a window, highlighting the fine texture of the clay and the fabric of his apron. Captured with an 85mm portrait lens, resulting in a soft, blurred background (bokeh). The overall mood is serene and masterful."}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > photorealistic_example.png

```

![一张逼真的特写肖像照片，照片中是一位年长的日本陶艺家...](images\photorealistic_example.png)
*一位年长的日本陶艺家的特写肖像，照片级真实感...*

#### 2. 风格化插画和贴纸

如需创建贴纸、图标或素材资源，请明确说明样式并要求使用透明背景。

### 模板

```
A [style] sticker of a [subject], featuring [key characteristics] and a
[color palette]. The design should have [line style] and [shading style].
The background must be transparent.

```

### 提示

```
A kawaii-style sticker of a happy red panda wearing a tiny bamboo hat. It's
munching on a green bamboo leaf. The design features bold, clean outlines,
simple cel-shading, and a vibrant color palette. The background must be white.

```

### Python

```python
from google import genai
from google.genai import types

client = genai.Client()

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents="A kawaii-style sticker of a happy red panda wearing a tiny bamboo hat. It's munching on a green bamboo leaf. The design features bold, clean outlines, simple cel-shading, and a vibrant color palette. The background must be white.",
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("red_panda_sticker.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "A kawaii-style sticker of a happy red panda wearing a tiny bamboo hat. It's munching on a green bamboo leaf. The design features bold, clean outlines, simple cel-shading, and a vibrant color palette. The background must be white.";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("red_panda_sticker.png", buffer);
      console.log("Image saved as red_panda_sticker.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "google.golang.org/genai"
)

func main() {

    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }

    result, _ := client.Models.GenerateContent(
        ctx,
        "gemini-2.5-flash-image",
        genai.Text("A kawaii-style sticker of a happy red panda wearing a tiny bamboo hat. It's munching on a green bamboo leaf. The design features bold, clean outlines, simple cel-shading, and a vibrant color palette. The background must be white."),
    )

    for _, part := range result.Candidates[0].Content.Parts {
        if part.Text != "" {
            fmt.Println(part.Text)
        } else if part.InlineData != nil {
            imageBytes := part.InlineData.Data
            outputFilename := "red_panda_sticker.png"
            _ = os.WriteFile(outputFilename, imageBytes, 0644)
        }
    }
}

```

### REST

```bash
curl -s -X POST
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "A kawaii-style sticker of a happy red panda wearing a tiny bamboo hat. It'"'"'s munching on a green bamboo leaf. The design features bold, clean outlines, simple cel-shading, and a vibrant color palette. The background must be white."}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > red_panda_sticker.png

```

![一张可爱风格的贴纸，上面画着一个开心的红色...](images\red_panda_sticker.png)
*一张可爱风格的贴纸，上面是一只快乐的小熊猫...*

#### 3. 图片中的文字准确无误

Gemini 在呈现文本方面表现出色。清楚说明文字、字体样式（描述性）和整体设计。

### 模板

```
Create a [image type] for [brand/concept] with the text "[text to render]"
in a [font style]. The design should be [style description], with a
[color scheme].

```

### 提示

```
Create a modern, minimalist logo for a coffee shop called 'The Daily Grind'.
The text should be in a clean, bold, sans-serif font. The design should
feature a simple, stylized icon of a a coffee bean seamlessly integrated
with the text. The color scheme is black and white.

```

### Python

```python
from google import genai
from google.genai import types    

client = genai.Client()

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents="Create a modern, minimalist logo for a coffee shop called 'The Daily Grind'. The text should be in a clean, bold, sans-serif font. The design should feature a simple, stylized icon of a a coffee bean seamlessly integrated with the text. The color scheme is black and white.",
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("logo_example.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "Create a modern, minimalist logo for a coffee shop called 'The Daily Grind'. The text should be in a clean, bold, sans-serif font. The design should feature a simple, stylized icon of a a coffee bean seamlessly integrated with the text. The color scheme is black and white.";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("logo_example.png", buffer);
      console.log("Image saved as logo_example.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "google.golang.org/genai"
)

func main() {

    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }

    result, _ := client.Models.GenerateContent(
        ctx,
        "gemini-2.5-flash-image",
        genai.Text("Create a modern, minimalist logo for a coffee shop called 'The Daily Grind'. The text should be in a clean, bold, sans-serif font. The design should feature a simple, stylized icon of a a coffee bean seamlessly integrated with the text. The color scheme is black and white."),
    )

    for _, part := range result.Candidates[0].Content.Parts {
        if part.Text != "" {
            fmt.Println(part.Text)
        } else if part.InlineData != nil {
            imageBytes := part.InlineData.Data
            outputFilename := "logo_example.png"
            _ = os.WriteFile(outputFilename, imageBytes, 0644)
        }
    }
}

```

### REST

```bash
curl -s -X POST
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "Create a modern, minimalist logo for a coffee shop called '"'"'The Daily Grind'"'"'. The text should be in a clean, bold, sans-serif font. The design should feature a simple, stylized icon of a a coffee bean seamlessly integrated with the text. The color scheme is black and white."}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > logo_example.png

```

![为名为“The Daily Grind”的咖啡店设计一个现代简约的徽标...](images\logo_example.png)
*为一家名为“The Daily Grind”的咖啡店设计一个现代简约的徽标...*

#### 4. 产品模型和商业摄影

非常适合为电子商务、广告或品牌宣传制作清晰专业的商品照片。

### 模板

```
A high-resolution, studio-lit product photograph of a [product description]
on a [background surface/description]. The lighting is a [lighting setup,
e.g., three-point softbox setup] to [lighting purpose]. The camera angle is
a [angle type] to showcase [specific feature]. Ultra-realistic, with sharp
focus on [key detail]. [Aspect ratio].

```

### 提示

```
A high-resolution, studio-lit product photograph of a minimalist ceramic
coffee mug in matte black, presented on a polished concrete surface. The
lighting is a three-point softbox setup designed to create soft, diffused
highlights and eliminate harsh shadows. The camera angle is a slightly
elevated 45-degree shot to showcase its clean lines. Ultra-realistic, with
sharp focus on the steam rising from the coffee. Square image.

```

### Python

```python
from google import genai
from google.genai import types

client = genai.Client()

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents="A high-resolution, studio-lit product photograph of a minimalist ceramic coffee mug in matte black, presented on a polished concrete surface. The lighting is a three-point softbox setup designed to create soft, diffused highlights and eliminate harsh shadows. The camera angle is a slightly elevated 45-degree shot to showcase its clean lines. Ultra-realistic, with sharp focus on the steam rising from the coffee. Square image.",
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("product_mockup.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "A high-resolution, studio-lit product photograph of a minimalist ceramic coffee mug in matte black, presented on a polished concrete surface. The lighting is a three-point softbox setup designed to create soft, diffused highlights and eliminate harsh shadows. The camera angle is a slightly elevated 45-degree shot to showcase its clean lines. Ultra-realistic, with sharp focus on the steam rising from the coffee. Square image.";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("product_mockup.png", buffer);
      console.log("Image saved as product_mockup.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "google.golang.org/genai"
)

func main() {

    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }

    result, _ := client.Models.GenerateContent(
        ctx,
        "gemini-2.5-flash-image",
        genai.Text("A high-resolution, studio-lit product photograph of a minimalist ceramic coffee mug in matte black, presented on a polished concrete surface. The lighting is a three-point softbox setup designed to create soft, diffused highlights and eliminate harsh shadows. The camera angle is a slightly elevated 45-degree shot to showcase its clean lines. Ultra-realistic, with sharp focus on the steam rising from the coffee. Square image."),
    )

    for _, part := range result.Candidates[0].Content.Parts {
        if part.Text != "" {
            fmt.Println(part.Text)
        } else if part.InlineData != nil {
            imageBytes := part.InlineData.Data
            outputFilename := "product_mockup.png"
            _ = os.WriteFile(outputFilename, imageBytes, 0644)
        }
    }
}

```

### REST

```bash
curl -s -X POST
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "A high-resolution, studio-lit product photograph of a minimalist ceramic coffee mug in matte black, presented on a polished concrete surface. The lighting is a three-point softbox setup designed to create soft, diffused highlights and eliminate harsh shadows. The camera angle is a slightly elevated 45-degree shot to showcase its clean lines. Ultra-realistic, with sharp focus on the steam rising from the coffee. Square image."}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > product_mockup.png

```

![一张高分辨率的棚拍商品照片，展示的是一个极简风格的陶瓷咖啡杯...](images\product_mockup.png)
*一张高分辨率的棚拍商品照片，照片中是一个极简风格的陶瓷咖啡杯...*

#### 5. 极简风格和负空间设计

非常适合用于创建网站、演示或营销材料的背景，以便在其中叠加文字。

### 模板

```
A minimalist composition featuring a single [subject] positioned in the
[bottom-right/top-left/etc.] of the frame. The background is a vast, empty
[color] canvas, creating significant negative space. Soft, subtle lighting.
[Aspect ratio].

```

### 提示

```
A minimalist composition featuring a single, delicate red maple leaf
positioned in the bottom-right of the frame. The background is a vast, empty
off-white canvas, creating significant negative space for text. Soft,
diffused lighting from the top left. Square image.

```

### Python

```python
from google import genai
from google.genai import types    

client = genai.Client()

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents="A minimalist composition featuring a single, delicate red maple leaf positioned in the bottom-right of the frame. The background is a vast, empty off-white canvas, creating significant negative space for text. Soft, diffused lighting from the top left. Square image.",
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("minimalist_design.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "A minimalist composition featuring a single, delicate red maple leaf positioned in the bottom-right of the frame. The background is a vast, empty off-white canvas, creating significant negative space for text. Soft, diffused lighting from the top left. Square image.";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("minimalist_design.png", buffer);
      console.log("Image saved as minimalist_design.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "google.golang.org/genai"
)

func main() {

    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }

    result, _ := client.Models.GenerateContent(
        ctx,
        "gemini-2.5-flash-image",
        genai.Text("A minimalist composition featuring a single, delicate red maple leaf positioned in the bottom-right of the frame. The background is a vast, empty off-white canvas, creating significant negative space for text. Soft, diffused lighting from the top left. Square image."),
    )

    for _, part := range result.Candidates[0].Content.Parts {
        if part.Text != "" {
            fmt.Println(part.Text)
        } else if part.InlineData != nil {
            imageBytes := part.InlineData.Data
            outputFilename := "minimalist_design.png"
            _ = os.WriteFile(outputFilename, imageBytes, 0644)
        }
    }
}

```

### REST

```bash
curl -s -X POST
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "A minimalist composition featuring a single, delicate red maple leaf positioned in the bottom-right of the frame. The background is a vast, empty off-white canvas, creating significant negative space for text. Soft, diffused lighting from the top left. Square image."}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > minimalist_design.png

```

![一幅极简主义构图，画面中只有一片精致的红枫叶...](images\minimalist_design.png)
*一幅极简主义构图，画面中只有一片精致的红枫叶...*

#### 6. 连续艺术（漫画分格 / 故事板）

以角色一致性和场景描述为基础，为视觉故事讲述创建分格。

### 模板

```
A single comic book panel in a [art style] style. In the foreground,
[character description and action]. In the background, [setting details].
The panel has a [dialogue/caption box] with the text "[Text]". The lighting
creates a [mood] mood. [Aspect ratio].

```

### 提示

```
A single comic book panel in a gritty, noir art style with high-contrast
black and white inks. In the foreground, a detective in a trench coat stands
under a flickering streetlamp, rain soaking his shoulders. In the
background, the neon sign of a desolate bar reflects in a puddle. A caption
box at the top reads "The city was a tough place to keep secrets." The
lighting is harsh, creating a dramatic, somber mood. Landscape.

```

### Python

```python
from google import genai
from google.genai import types

client = genai.Client()

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents="A single comic book panel in a gritty, noir art style with high-contrast black and white inks. In the foreground, a detective in a trench coat stands under a flickering streetlamp, rain soaking his shoulders. In the background, the neon sign of a desolate bar reflects in a puddle. A caption box at the top reads \"The city was a tough place to keep secrets.\" The lighting is harsh, creating a dramatic, somber mood. Landscape.",
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("comic_panel.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const prompt =
    "A single comic book panel in a gritty, noir art style with high-contrast black and white inks. In the foreground, a detective in a trench coat stands under a flickering streetlamp, rain soaking his shoulders. In the background, the neon sign of a desolate bar reflects in a puddle. A caption box at the top reads \"The city was a tough place to keep secrets.\" The lighting is harsh, creating a dramatic, somber mood. Landscape.";

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("comic_panel.png", buffer);
      console.log("Image saved as comic_panel.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
    "context"
    "fmt"
    "log"
    "os"
    "google.golang.org/genai"
)

func main() {

    ctx := context.Background()
    client, err := genai.NewClient(ctx, nil)
    if err != nil {
        log.Fatal(err)
    }

    result, _ := client.Models.GenerateContent(
        ctx,
        "gemini-2.5-flash-image",
        genai.Text("A single comic book panel in a gritty, noir art style with high-contrast black and white inks. In the foreground, a detective in a trench coat stands under a flickering streetlamp, rain soaking his shoulders. In the background, the neon sign of a desolate bar reflects in a puddle. A caption box at the top reads \"The city was a tough place to keep secrets.\" The lighting is harsh, creating a dramatic, somber mood. Landscape."),
    )

    for _, part := range result.Candidates[0].Content.Parts {
        if part.Text != "" {
            fmt.Println(part.Text)
        } else if part.InlineData != nil {
            imageBytes := part.InlineData.Data
            outputFilename := "comic_panel.png"
            _ = os.WriteFile(outputFilename, imageBytes, 0644)
        }
    }
}

```

### REST

```bash
curl -s -X POST
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{
      "parts": [
        {"text": "A single comic book panel in a gritty, noir art style with high-contrast black and white inks. In the foreground, a detective in a trench coat stands under a flickering streetlamp, rain soaking his shoulders. In the background, the neon sign of a desolate bar reflects in a puddle. A caption box at the top reads \"The city was a tough place to keep secrets.\" The lighting is harsh, creating a dramatic, somber mood. Landscape."}
      ]
    }]
  }' \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > comic_panel.png

```

![一张采用粗犷黑色电影艺术风格的漫画单格画面...](images\comic_panel.png)
*采用粗犷的黑色电影艺术风格的单幅漫画书画面...*

### 用于修改图片的提示

以下示例展示了如何提供图片以及文本提示，以进行编辑、构图和风格迁移。

#### 1. 添加和移除元素

提供图片并描述您的更改。模型将与原始图片的风格、光照和透视效果保持一致。

### 模板

```
Using the provided image of [subject], please [add/remove/modify] [element]
to/from the scene. Ensure the change is [description of how the change should
integrate].

```

### 提示

```
"Using the provided image of my cat, please add a small, knitted wizard hat
on its head. Make it look like it's sitting comfortably and matches the soft
lighting of the photo."

```

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

# Base image prompt: "A photorealistic picture of a fluffy ginger cat sitting on a wooden floor, looking directly at the camera. Soft, natural light from a window."
image_input = Image.open('/path/to/your/cat_photo.png')
text_input = """Using the provided image of my cat, please add a small, knitted wizard hat on its head. Make it look like it's sitting comfortably and not falling off."""

# Generate an image from a text prompt
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[text_input, image_input],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("cat_with_hat.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const imagePath = "/path/to/your/cat_photo.png";
  const imageData = fs.readFileSync(imagePath);
  const base64Image = imageData.toString("base64");

  const prompt = [
    { text: "Using the provided image of my cat, please add a small, knitted wizard hat on its head. Make it look like it's sitting comfortably and not falling off." },
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image,
      },
    },
  ];

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("cat_with_hat.png", buffer);
      console.log("Image saved as cat_with_hat.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
  "context"
  "fmt"
  "log"
  "os"
  "google.golang.org/genai"
)

func main() {

  ctx := context.Background()
  client, err := genai.NewClient(ctx, nil)
  if err != nil {
      log.Fatal(err)
  }

  imagePath := "/path/to/your/cat_photo.png"
  imgData, _ := os.ReadFile(imagePath)

  parts := []*genai.Part{
    genai.NewPartFromText("Using the provided image of my cat, please add a small, knitted wizard hat on its head. Make it look like it's sitting comfortably and not falling off."),
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData,
      },
    },
  }

  contents := []*genai.Content{
    genai.NewContentFromParts(parts, genai.RoleUser),
  }

  result, _ := client.Models.GenerateContent(
      ctx,
      "gemini-2.5-flash-image",
      contents,
  )

  for _, part := range result.Candidates[0].Content.Parts {
      if part.Text != "" {
          fmt.Println(part.Text)
      } else if part.InlineData != nil {
          imageBytes := part.InlineData.Data
          outputFilename := "cat_with_hat.png"
          _ = os.WriteFile(outputFilename, imageBytes, 0644)
      }
  }
}

```

### REST

```bash
IMG_PATH=/path/to/your/cat_photo.png

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {\"text\": \"Using the provided image of my cat, please add a small, knitted wizard hat on its head. Make it look like it's sitting comfortably and not falling off.\"},
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG_BASE64\"
              }
            }
        ]
      }]
    }"  \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > cat_with_hat.png

```

| 输入 | 输出 |
| --- | --- |
| 一张逼真的图片，内容是一只毛绒绒的姜黄色猫... | Using the provided image of my cat, please add a small, knitted wizard hat... |

#### 2. 局部重绘（语义遮盖）

通过对话定义“蒙版”，以修改图片的特定部分，同时保持其余部分不变。

### 模板

```
Using the provided image, change only the [specific element] to [new
element/description]. Keep everything else in the image exactly the same,
preserving the original style, lighting, and composition.

```

### 提示

```
"Using the provided image of a living room, change only the blue sofa to be
a vintage, brown leather chesterfield sofa. Keep the rest of the room,
including the pillows on the sofa and the lighting, unchanged."

```

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

# Base image prompt: "A wide shot of a modern, well-lit living room with a prominent blue sofa in the center. A coffee table is in front of it and a large window is in the background."
living_room_image = Image.open('/path/to/your/living_room.png')
text_input = """Using the provided image of a living room, change only the blue sofa to be a vintage, brown leather chesterfield sofa. Keep the rest of the room, including the pillows on the sofa and the lighting, unchanged."""

# Generate an image from a text prompt
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[living_room_image, text_input],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("living_room_edited.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const imagePath = "/path/to/your/living_room.png";
  const imageData = fs.readFileSync(imagePath);
  const base64Image = imageData.toString("base64");

  const prompt = [
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image,
      },
    },
    { text: "Using the provided image of a living room, change only the blue sofa to be a vintage, brown leather chesterfield sofa. Keep the rest of the room, including the pillows on the sofa and the lighting, unchanged." },
  ];

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("living_room_edited.png", buffer);
      console.log("Image saved as living_room_edited.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
  "context"
  "fmt"
  "log"
  "os"
  "google.golang.org/genai"
)

func main() {

  ctx := context.Background()
  client, err := genai.NewClient(ctx, nil)
  if err != nil {
      log.Fatal(err)
  }

  imagePath := "/path/to/your/living_room.png"
  imgData, _ := os.ReadFile(imagePath)

  parts := []*genai.Part{
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData,
      },
    },
    genai.NewPartFromText("Using the provided image of a living room, change only the blue sofa to be a vintage, brown leather chesterfield sofa. Keep the rest of the room, including the pillows on the sofa and the lighting, unchanged."),
  }

  contents := []*genai.Content{
    genai.NewContentFromParts(parts, genai.RoleUser),
  }

  result, _ := client.Models.GenerateContent(
      ctx,
      "gemini-2.5-flash-image",
      contents,
  )

  for _, part := range result.Candidates[0].Content.Parts {
      if part.Text != "" {
          fmt.Println(part.Text)
      } else if part.InlineData != nil {
          imageBytes := part.InlineData.Data
          outputFilename := "living_room_edited.png"
          _ = os.WriteFile(outputFilename, imageBytes, 0644)
      }
  }
}

```

### REST

```bash
IMG_PATH=/path/to/your/living_room.png

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG_BASE64\"
              }
            },
            {\"text\": \"Using the provided image of a living room, change only the blue sofa to be a vintage, brown leather chesterfield sofa. Keep the rest of the room, including the pillows on the sofa and the lighting, unchanged.\"}
        ]
      }]
    }"  \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > living_room_edited.png

```

| 输入 | 输出 |
| --- | --- |
| 一间光线充足的现代客厅的广角镜头… | 使用提供的客厅图片，将蓝色沙发更改为复古棕色真皮切斯特菲尔德沙发... |

#### 3. 风格迁移

提供一张图片，并让模型以不同的艺术风格重新创作其内容。

### 模板

```
Transform the provided photograph of [subject] into the artistic style of [artist/art style]. Preserve the original composition but render it with [description of stylistic elements].

```

### 提示

```
"Transform the provided photograph of a modern city street at night into the artistic style of Vincent van Gogh's 'Starry Night'. Preserve the original composition of buildings and cars, but render all elements with swirling, impasto brushstrokes and a dramatic palette of deep blues and bright yellows."

```

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

# Base image prompt: "A photorealistic, high-resolution photograph of a busy city street in New York at night, with bright neon signs, yellow taxis, and tall skyscrapers."
city_image = Image.open('/path/to/your/city.png')
text_input = """Transform the provided photograph of a modern city street at night into the artistic style of Vincent van Gogh's 'Starry Night'. Preserve the original composition of buildings and cars, but render all elements with swirling, impasto brushstrokes and a dramatic palette of deep blues and bright yellows."""

# Generate an image from a text prompt
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[city_image, text_input],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("city_style_transfer.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const imagePath = "/path/to/your/city.png";
  const imageData = fs.readFileSync(imagePath);
  const base64Image = imageData.toString("base64");

  const prompt = [
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image,
      },
    },
    { text: "Transform the provided photograph of a modern city street at night into the artistic style of Vincent van Gogh's 'Starry Night'. Preserve the original composition of buildings and cars, but render all elements with swirling, impasto brushstrokes and a dramatic palette of deep blues and bright yellows." },
  ];

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("city_style_transfer.png", buffer);
      console.log("Image saved as city_style_transfer.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
  "context"
  "fmt"
  "log"
  "os"
  "google.golang.org/genai"
)

func main() {

  ctx := context.Background()
  client, err := genai.NewClient(ctx, nil)
  if err != nil {
      log.Fatal(err)
  }

  imagePath := "/path/to/your/city.png"
  imgData, _ := os.ReadFile(imagePath)

  parts := []*genai.Part{
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData,
      },
    },
    genai.NewPartFromText("Transform the provided photograph of a modern city street at night into the artistic style of Vincent van Gogh's 'Starry Night'. Preserve the original composition of buildings and cars, but render all elements with swirling, impasto brushstrokes and a dramatic palette of deep blues and bright yellows."),
  }

  contents := []*genai.Content{
    genai.NewContentFromParts(parts, genai.RoleUser),
  }

  result, _ := client.Models.GenerateContent(
      ctx,
      "gemini-2.5-flash-image",
      contents,
  )

  for _, part := range result.Candidates[0].Content.Parts {
      if part.Text != "" {
          fmt.Println(part.Text)
      } else if part.InlineData != nil {
          imageBytes := part.InlineData.Data
          outputFilename := "city_style_transfer.png"
          _ = os.WriteFile(outputFilename, imageBytes, 0644)
      }
  }
}

```

### REST

```bash
IMG_PATH=/path/to/your/city.png

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG_BASE64\"
              }
            },
            {\"text\": \"Transform the provided photograph of a modern city street at night into the artistic style of Vincent van Gogh's 'Starry Night'. Preserve the original composition of buildings and cars, but render all elements with swirling, impasto brushstrokes and a dramatic palette of deep blues and bright yellows.\"}
        ]
      }]
    }"  \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > city_style_transfer.png

```

| 输入 | 输出 |
| --- | --- |
| 一张逼真的高分辨率照片，拍摄的是繁忙的城市街道... | 将提供的夜间现代城市街道照片改造成... |

#### 4. 高级合成：组合多张图片

提供多张图片作为上下文，以创建新的合成场景。这非常适合制作产品模型或创意拼贴画。

### 模板

```
Create a new image by combining the elements from the provided images. Take
the [element from image 1] and place it with/on the [element from image 2].
The final image should be a [description of the final scene].

```

### 提示

```
"Create a professional e-commerce fashion photo. Take the blue floral dress
from the first image and let the woman from the second image wear it.
Generate a realistic, full-body shot of the woman wearing the dress, with
the lighting and shadows adjusted to match the outdoor environment."

```

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

# Base image prompts:
# 1. Dress: "A professionally shot photo of a blue floral summer dress on a plain white background, ghost mannequin style."
# 2. Model: "Full-body shot of a woman with her hair in a bun, smiling, standing against a neutral grey studio background."
dress_image = Image.open('/path/to/your/dress.png')
model_image = Image.open('/path/to/your/model.png')

text_input = """Create a professional e-commerce fashion photo. Take the blue floral dress from the first image and let the woman from the second image wear it. Generate a realistic, full-body shot of the woman wearing the dress, with the lighting and shadows adjusted to match the outdoor environment."""

# Generate an image from a text prompt
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[dress_image, model_image, text_input],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("fashion_ecommerce_shot.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const imagePath1 = "/path/to/your/dress.png";
  const imageData1 = fs.readFileSync(imagePath1);
  const base64Image1 = imageData1.toString("base64");
  const imagePath2 = "/path/to/your/model.png";
  const imageData2 = fs.readFileSync(imagePath2);
  const base64Image2 = imageData2.toString("base64");

  const prompt = [
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image1,
      },
    },
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image2,
      },
    },
    { text: "Create a professional e-commerce fashion photo. Take the blue floral dress from the first image and let the woman from the second image wear it. Generate a realistic, full-body shot of the woman wearing the dress, with the lighting and shadows adjusted to match the outdoor environment." },
  ];

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("fashion_ecommerce_shot.png", buffer);
      console.log("Image saved as fashion_ecommerce_shot.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
  "context"
  "fmt"
  "log"
  "os"
  "google.golang.org/genai"
)

func main() {

  ctx := context.Background()
  client, err := genai.NewClient(ctx, nil)
  if err != nil {
      log.Fatal(err)
  }

  imgData1, _ := os.ReadFile("/path/to/your/dress.png")
  imgData2, _ := os.ReadFile("/path/to/your/model.png")

  parts := []*genai.Part{
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData1,
      },
    },
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData2,
      },
    },
    genai.NewPartFromText("Create a professional e-commerce fashion photo. Take the blue floral dress from the first image and let the woman from the second image wear it. Generate a realistic, full-body shot of the woman wearing the dress, with the lighting and shadows adjusted to match the outdoor environment."),
  }

  contents := []*genai.Content{
    genai.NewContentFromParts(parts, genai.RoleUser),
  }

  result, _ := client.Models.GenerateContent(
      ctx,
      "gemini-2.5-flash-image",
      contents,
  )

  for _, part := range result.Candidates[0].Content.Parts {
      if part.Text != "" {
          fmt.Println(part.Text)
      } else if part.InlineData != nil {
          imageBytes := part.InlineData.Data
          outputFilename := "fashion_ecommerce_shot.png"
          _ = os.WriteFile(outputFilename, imageBytes, 0644)
      }
  }
}

```

### REST

```bash
IMG_PATH1=/path/to/your/dress.png
IMG_PATH2=/path/to/your/model.png

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG1_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH1" 2>&1)
IMG2_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH2" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG1_BASE64\"
              }
            },
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG2_BASE64\"
              }
            },
            {\"text\": \"Create a professional e-commerce fashion photo. Take the blue floral dress from the first image and let the woman from the second image wear it. Generate a realistic, full-body shot of the woman wearing the dress, with the lighting and shadows adjusted to match the outdoor environment.\"}
        ]
      }]
    }"  \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > fashion_ecommerce_shot.png

```

| 输入值 1 | 输入值 2 | 输出 |
| --- | --- | --- |
| 一张专业拍摄的照片，照片中是一件蓝色印花夏季连衣裙… | Full-body shot of a woman with her hair in a bun... | 拍摄专业的电子商务时尚照片... |

#### 5. 高保真细节保留

为确保在编辑过程中保留关键细节（例如面部或徽标），请在编辑请求中详细描述这些细节。

### 模板

```
Using the provided images, place [element from image 2] onto [element from
image 1]. Ensure that the features of [element from image 1] remain
completely unchanged. The added element should [description of how the
element should integrate].

```

### 提示

```
"Take the first image of the woman with brown hair, blue eyes, and a neutral
expression. Add the logo from the second image onto her black t-shirt.
Ensure the woman's face and features remain completely unchanged. The logo
should look like it's naturally printed on the fabric, following the folds
of the shirt."

```

### Python

```python
from google import genai
from google.genai import types
from PIL import Image

client = genai.Client()

# Base image prompts:
# 1. Woman: "A professional headshot of a woman with brown hair and blue eyes, wearing a plain black t-shirt, against a neutral studio background."
# 2. Logo: "A simple, modern logo with the letters 'G' and 'A' in a white circle."
woman_image = Image.open('/path/to/your/woman.png')
logo_image = Image.open('/path/to/your/logo.png')
text_input = """Take the first image of the woman with brown hair, blue eyes, and a neutral expression. Add the logo from the second image onto her black t-shirt. Ensure the woman's face and features remain completely unchanged. The logo should look like it's naturally printed on the fabric, following the folds of the shirt."""

# Generate an image from a text prompt
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[woman_image, logo_image, text_input],
)

for part in response.parts:
    if part.text is not None:
        print(part.text)
    elif part.inline_data is not None:
        image = part.as_image()
        image.save("woman_with_logo.png")

```

### JavaScript

```javascript
import { GoogleGenAI } from "@google/genai";
import * as fs from "node:fs";

async function main() {

  const ai = new GoogleGenAI({});

  const imagePath1 = "/path/to/your/woman.png";
  const imageData1 = fs.readFileSync(imagePath1);
  const base64Image1 = imageData1.toString("base64");
  const imagePath2 = "/path/to/your/logo.png";
  const imageData2 = fs.readFileSync(imagePath2);
  const base64Image2 = imageData2.toString("base64");

  const prompt = [
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image1,
      },
    },
    {
      inlineData: {
        mimeType: "image/png",
        data: base64Image2,
      },
    },
    { text: "Take the first image of the woman with brown hair, blue eyes, and a neutral expression. Add the logo from the second image onto her black t-shirt. Ensure the woman's face and features remain completely unchanged. The logo should look like it's naturally printed on the fabric, following the folds of the shirt." },
  ];

  const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
  });
  for (const part of response.candidates[0].content.parts) {
    if (part.text) {
      console.log(part.text);
    } else if (part.inlineData) {
      const imageData = part.inlineData.data;
      const buffer = Buffer.from(imageData, "base64");
      fs.writeFileSync("woman_with_logo.png", buffer);
      console.log("Image saved as woman_with_logo.png");
    }
  }
}

main();

```

### Go

```go
package main

import (
  "context"
  "fmt"
  "log"
  "os"
  "google.golang.org/genai"
)

func main() {

  ctx := context.Background()
  client, err := genai.NewClient(ctx, nil)
  if err != nil {
      log.Fatal(err)
  }

  imgData1, _ := os.ReadFile("/path/to/your/woman.png")
  imgData2, _ := os.ReadFile("/path/to/your/logo.png")

  parts := []*genai.Part{
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData1,
      },
    },
    &genai.Part{
      InlineData: &genai.Blob{
        MIMEType: "image/png",
        Data:     imgData2,
      },
    },
    genai.NewPartFromText("Take the first image of the woman with brown hair, blue eyes, and a neutral expression. Add the logo from the second image onto her black t-shirt. Ensure the woman's face and features remain completely unchanged. The logo should look like it's naturally printed on the fabric, following the folds of the shirt."),
  }

  contents := []*genai.Content{
    genai.NewContentFromParts(parts, genai.RoleUser),
  }

  result, _ := client.Models.GenerateContent(
      ctx,
      "gemini-2.5-flash-image",
      contents,
  )

  for _, part := range result.Candidates[0].Content.Parts {
      if part.Text != "" {
          fmt.Println(part.Text)
      } else if part.InlineData != nil {
          imageBytes := part.InlineData.Data
          outputFilename := "woman_with_logo.png"
          _ = os.WriteFile(outputFilename, imageBytes, 0644)
      }
  }
}

```

### REST

```bash
IMG_PATH1=/path/to/your/woman.png
IMG_PATH2=/path/to/your/logo.png

if [[ "$(base64 --version 2>&1)" = *"FreeBSD"* ]]; then
  B64FLAGS="--input"
else
  B64FLAGS="-w0"
fi

IMG1_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH1" 2>&1)
IMG2_BASE64=$(base64 "$B64FLAGS" "$IMG_PATH2" 2>&1)

curl -X POST \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent" \
    -H "x-goog-api-key: $GEMINI_API_KEY" \
    -H 'Content-Type: application/json' \
    -d "{
      \"contents\": [{
        \"parts\":[
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG1_BASE64\"
              }
            },
            {
              \"inline_data\": {
                \"mime_type\":\"image/png\",
                \"data\": \"$IMG2_BASE64\"
              }
            },
            {\"text\": \"Take the first image of the woman with brown hair, blue eyes, and a neutral expression. Add the logo from the second image onto her black t-shirt. Ensure the woman's face and features remain completely unchanged. The logo should look like it's naturally printed on the fabric, following the folds of the shirt.\"}
        ]
      }]
    }"  \
  | grep -o '"data": "[^"]*"' \
  | cut -d'"' -f4 \
  | base64 --decode > woman_with_logo.png

```

| 输入值 1 | 输入值 2 | 输出 |
| --- | --- | --- |
| 一张专业头像，一位棕色头发、蓝色眼睛的女性... | 一个简单的现代徽标，包含字母“G”和“A”... | 拍摄第一张照片，照片中的女子留着棕色头发，有着蓝色眼睛，面部表情平静... |

### 最佳做法

如需将照片效果从“好”提升到“出色”，请将以下专业策略融入您的工作流程。

- 具体化：您提供的信息越详细，对输出结果的掌控程度就越高。与其使用“奇幻盔甲”，不如具体描述：“华丽的精灵板甲，蚀刻着银叶图案，带有高领和猎鹰翅膀形状的肩甲。”
- 提供上下文和意图：说明图片的用途。模型对上下文的理解会影响最终输出。例如，“为高端极简护肤品牌设计徽标”的效果要好于“设计徽标”。
- 迭代和优化：不要指望第一次尝试就能生成完美的图片。利用模型的对话特性进行小幅更改。使用后续提示，例如“这很棒，但你能让光线更暖一些吗？”或“保持所有内容不变，但让角色的表情更严肃一些。”
- 使用分步指令：对于包含许多元素的复杂场景，请将提示拆分为多个步骤。“首先，创建一个宁静、薄雾弥漫的黎明森林的背景。然后，在前景中添加一个长满苔藓的古老石制祭坛。最后，将一把发光的剑放在祭坛顶部。”
- 使用“语义负面提示”：不要说“没有汽车”，而是通过说“一条没有交通迹象的空旷、荒凉的街道”来正面描述所需的场景。
- 控制镜头：使用摄影和电影语言来控制构图。例如wide-angle shot、macro shot、low-angle
perspective等字词。

## 限制

- 为获得最佳性能，请使用以下语言：英语、西班牙语（墨西哥）、日语（日本）、中文（中国）、印地语（印度）。
- 图片生成不支持音频或视频输入。
- 模型不一定会严格按照用户明确要求的图片输出数量来生成图片。
- 该模型在输入最多 3 张图片时效果最佳。
- 为图片生成文字时，最好先生成文字，然后再要求生成包含该文字的图片，这样 Gemini 的效果最好。
- 所有生成的图片都包含SynthID 水印。

## 可选配置

您可以选择在generate_content调用的config字段中配置模型输出的响应模态和宽高比。

### 输出类型

默认情况下，模型会返回文本和图片响应（即response_modalities=['Text', 'Image']）。您可以使用response_modalities=['Image']将响应配置为仅返回图片而不返回文本。

### Python

```python
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt],
    config=types.GenerateContentConfig(
        response_modalities=['Image']
    )
)

```

### JavaScript

```javascript
const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
    config: {
        responseModalities: ['Image']
    }
  });

```

### Go

```go
result, _ := client.Models.GenerateContent(
    ctx,
    "gemini-2.5-flash-image",
    genai.Text("Create a picture of a nano banana dish in a " +
                " fancy restaurant with a Gemini theme"),
    &genai.GenerateContentConfig{
        ResponseModalities: "Image",
    },
  )

```

### REST

```bash
-d '{
  "contents": [{
    "parts": [
      {"text": "Create a picture of a nano banana dish in a fancy restaurant with a Gemini theme"}
    ]
  }],
  "generationConfig": {
    "responseModalities": ["Image"]
  }
}' \

```

### 宽高比

默认情况下，模型会使输出图片的大小与输入图片的大小保持一致，否则会生成 1:1 的正方形图片。
您可以使用响应请求中image_config下的aspect_ratio字段来控制输出图片的宽高比，如下所示：

### Python

```python
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt],
    config=types.GenerateContentConfig(
        image_config=types.ImageConfig(
            aspect_ratio="16:9",
        )
    )
)

```

### JavaScript

```javascript
const response = await ai.models.generateContent({
    model: "gemini-2.5-flash-image",
    contents: prompt,
    config: {
      imageConfig: {
        aspectRatio: "16:9",
      },
    }
  });

```

### Go

```go
result, _ := client.Models.GenerateContent(
    ctx,
    "gemini-2.5-flash-image",
    genai.Text("Create a picture of a nano banana dish in a " +
                " fancy restaurant with a Gemini theme"),
    &genai.GenerateContentConfig{
        ImageConfig: &genai.ImageConfig{
          AspectRatio: "16:9",
        },
    }
  )

```

### REST

```bash
-d '{
  "contents": [{
    "parts": [
      {"text": "Create a picture of a nano banana dish in a fancy restaurant with a Gemini theme"}
    ]
  }],
  "generationConfig": {
    "imageConfig": {
      "aspectRatio": "16:9"
    }
  }
}' \

```

下表列出了可用的不同宽高比以及生成的图片大小：

Gemini 2.5 Flash 图片

| 宽高比 | 分辨率 | 令牌 |
| --- | --- | --- |
| 1:1 | 1024x1024 | 1290 |
| 2:3 | 832x1248 | 1290 |
| 3:2 | 1248x832 | 1290 |
| 3:4 | 864x1184 | 1290 |
| 4:3 | 1184x864 | 1290 |
| 4:5 | 896x1152 | 1290 |
| 5:4 | 1152x896 | 1290 |
| 9:16 | 768x1344 | 1290 |
| 16:9 | 1344x768 | 1290 |
| 21:9 | 1536x672 | 1290 |

Gemini 3 Pro Image 预览版

| 宽高比 | 1K 分辨率 | 1,000 个令牌 | 2K 分辨率 | 2,000 个令牌 | 4K 分辨率 | 4,000 个令牌 |
| --- | --- | --- | --- | --- | --- | --- |
| 1:1 | 1024x1024 | 1210 | 2048 x 2048 | 1210 | 4096x4096 | 2000 |
| 2:3 | 848x1264 | 1210 | 1696x2528 | 1210 | 3392x5056 | 2000 |
| 3:2 | 1264x848 | 1210 | 2528x1696 | 1210 | 5056x3392 | 2000 |
| 3:4 | 896x1200 | 1210 | 1792x2400 | 1210 | 3584x4800 | 2000 |
| 4:3 | 1200x896 | 1210 | 2400x1792 | 1210 | 4800x3584 | 2000 |
| 4:5 | 928x1152 | 1210 | 1856x2304 | 1210 | 3712x4608 | 2000 |
| 5:4 | 1152x928 | 1210 | 2304x1856 | 1210 | 4608x3712 | 2000 |
| 9:16 | 768x1376 | 1210 | 1536x2752 | 1210 | 3072x5504 | 2000 |
| 16:9 | 1376x768 | 1210 | 2752x1536 | 1210 | 5504x3072 | 2000 |
| 21:9 | 1584x672 | 1210 | 3168x1344 | 1210 | 6336x2688 | 2000 |

## 何时使用 Imagen

除了使用 Gemini 的内置图片生成功能外，您还可以通过 Gemini API 访问我们专门的图片生成模型Imagen。

| 属性 | Imagen | Gemini 原生图片 |
| --- | --- | --- |
| 优势 | 模型擅长生成图片。 | 默认建议。无与伦比的灵活性、情境理解能力，以及简单易用的无蒙版修改功能。能够进行多轮对话式编辑。 |
| 可用性 | 已全面推出 | 预览版（允许用于生产环境） |
| 延迟时间 | 低：针对近乎实时的性能进行了优化。 | 提高。其高级功能需要更多计算资源。 |
| 费用 | 可经济高效地完成专业任务。$0.02/图片到 $0.12/图片 | 基于 token 的定价。图片输出每 100 万个 token 的费用为 30 美元（图片输出的 token 数为每张图片 1290 个 token，最高分辨率为 1024x1024 像素） |
| 推荐的任务 | 图片质量、写实度、艺术细节或特定风格（例如印象派、动漫）是首要考虑因素。融入品牌元素、风格，或生成徽标和产品设计。生成高级拼写或排版。 | 生成交织的文本和图片，实现文本和图片的无缝融合。通过单个提示组合多张图片中的创意元素。对图片进行高度精细的编辑，使用简单的语言命令修改单个元素，并以迭代方式处理图片。将一张图片中的特定设计或纹理应用到另一张图片，同时保留原始对象的外形和细节。 |

如果您刚开始使用 Imagen 生成图片，Imagen 4 应该是您的首选模型。如果需要处理高级用例或需要最佳图片质量，请选择 Imagen 4 Ultra（请注意，该模型一次只能生成一张图片）。

## 后续步骤

- 如需查看更多示例和代码示例，请参阅食谱指南。
- 查看Veo 指南，了解如何使用 Gemini API 生成视频。
- 如需详细了解 Gemini 模型，请参阅Gemini 模型。
