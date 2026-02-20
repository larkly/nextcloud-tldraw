import React from 'react'
import ReactDOM from 'react-dom/client'
import TldrawEditor from './TldrawEditor'
import './index.css'

// Polyfill for global if needed
// window.global = window;

const rootElement = document.getElementById('tldraw-root')

if (rootElement) {
  const { fileId, fileName, canWrite, wsServerUrl } = rootElement.dataset

  if (fileId && wsServerUrl) {
    // Initial token fetch
    fetch(`/apps/tldraw/token/${fileId}`)
      .then((res) => {
        if (!res.ok) throw new Error('Failed to fetch token')
        return res.json()
      })
      .then((data) => {
        ReactDOM.createRoot(rootElement).render(
          <React.StrictMode>
            <TldrawEditor
              fileId={fileId}
              fileName={fileName || 'Untitled'}
              canWrite={canWrite === 'true'}
              wsServerUrl={wsServerUrl}
              initialToken={data.token}
            />
          </React.StrictMode>
        )
      })
      .catch((err) => {
        console.error('Failed to initialize tldraw:', err)
        rootElement.innerText = 'Error loading drawing. Please check console.'
      })
  }
}
