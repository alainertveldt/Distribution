import {combineReducers, makeReducer} from '#/main/core/scaffolding/reducer'
import {makeListReducer} from '#/main/core/data/list/reducer'

import {
  UPDATE_CURRENT_HISTORY_SECTION,
  UPDATE_CURRENT_HISTORY_VERSION,
  UPDATE_CURRENT_HISTORY_COMPARE_SET,
  UPDATE_ACTIVE_CONTRIBUTION
} from '#/plugin/wiki/resources/wiki/history/store/actions'

const reducer = combineReducers({
  contributions: makeListReducer('history.contributions', {sortBy: { property: 'creationDate', direction: -1 }}, {
    invalidated: makeReducer(false, {
      [UPDATE_CURRENT_HISTORY_SECTION]: () => true
    })
  }),
  currentSection: makeReducer({}, {
    [UPDATE_CURRENT_HISTORY_SECTION]: (state, action) => action.section,
    [UPDATE_ACTIVE_CONTRIBUTION]: (state, action) => {
      return Object.assign({}, state, {activeContribution: action.contribution})
    }
  }),
  currentVersion: makeReducer({}, {
    [UPDATE_CURRENT_HISTORY_VERSION]: (state, action) => action.contribution
  }),
  compareSet: makeReducer([], {
    [UPDATE_CURRENT_HISTORY_COMPARE_SET]: (state, action) => action.contributions
  })
})

export {
  reducer
}