import { Tldraw } from 'tldraw'
import { useSync } from '@tldraw/sync'
import { useState } from 'react'
import 'tldraw/tldraw.css'
import { createAssetStore } from './asset-store'

interface TldrawEditorProps {
  fileId: string
  fileName: string
  canWrite: boolean
  wsServerUrl: string
  initialToken: string
}

export default function TldrawEditor({
  fileId,
  canWrite,
  wsServerUrl,
  initialToken,
}: TldrawEditorProps) {
  const [token] = useState(initialToken)

  // Construct WebSocket URL base
  const wsBaseUrl = wsServerUrl.replace('http', 'ws') + `/connect/${fileId}`

  const store = useSync({
    // We append the token as a query parameter. Since we are using short-lived tokens (60s),
    // this mitigates the risk of URL logging compared to long-lived tokens.
    uri: `${wsBaseUrl}?token=${token}`,
    assets: createAssetStore(wsServerUrl, token),
  })

  return (
    <div style={{ position: 'fixed', inset: 0 }}>
      <Tldraw
        store={store}
        onMount={(editor) => {
            // Set read-only state based on permissions
            editor.updateInstanceState({ isReadonly: !canWrite })
        }}
      />
    </div>
  )
}
